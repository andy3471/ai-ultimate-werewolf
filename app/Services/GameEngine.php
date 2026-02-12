<?php

namespace App\Services;

use App\Ai\Agents\DiscussionAgent;
use App\Ai\Agents\NightActionAgent;
use App\Ai\Agents\VoteAgent;
use App\Ai\Context\GameContext;
use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Events\GameEnded;
use App\Events\GamePhaseChanged;
use App\Events\PlayerActed;
use App\Events\PlayerEliminated;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Dusk;
use App\States\GamePhase\GameOver;
use App\States\GamePhase\NightDoctor;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;
use App\States\GameStatus\Finished;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Log;

class GameEngine
{
    protected const PHASE_DELAY_SECONDS = 2;

    public function __construct(
        protected RoleRegistry $roleRegistry,
        protected NightResolver $nightResolver,
        protected VoteResolver $voteResolver,
        protected GameContext $gameContext,
    ) {}

    /**
     * Assign roles to players and start the game.
     */
    public function startGame(Game $game): void
    {
        $game->refresh();

        // Already running — skip setup (idempotent for retries)
        if ($game->status instanceof Running) {
            return;
        }

        $players = $game->players()->get();
        $playerCount = $players->count();

        // Determine role distribution
        $roles = $this->distributeRoles($playerCount);

        // Shuffle and assign
        $shuffledRoles = collect($roles)->shuffle();

        foreach ($players as $index => $player) {
            $player->update(['role' => $shuffledRoles[$index]]);
        }

        // Transition game state
        $game->status->transitionTo(Running::class);
        $game->update(['round' => 1]);
        $game->phase->transitionTo(NightWerewolf::class);

        $this->broadcastPhaseChange($game);
    }

    /**
     * Run the full game loop. Called from a queued job.
     */
    public function run(Game $game): void
    {
        $maxRounds = 20; // Safety limit

        while ($game->round <= $maxRounds) {
            $game->refresh();

            if ($game->phase instanceof GameOver) {
                break;
            }

            try {
                $this->processCurrentPhase($game);
            } catch (\Throwable $e) {
                Log::error('GameEngine error', [
                    'game_id' => $game->id,
                    'phase' => $game->phase->getValue(),
                    'round' => $game->round,
                    'error' => $e->getMessage(),
                ]);

                $game->events()->create([
                    'round' => $game->round,
                    'phase' => $game->phase->getValue(),
                    'type' => 'error',
                    'data' => ['message' => 'An error occurred: '.$e->getMessage()],
                    'is_public' => true,
                ]);

                break;
            }

            sleep(self::PHASE_DELAY_SECONDS);
        }
    }

    /**
     * Process the current phase and advance to the next one.
     */
    protected function processCurrentPhase(Game $game): void
    {
        $phase = $game->phase;

        match (true) {
            $phase instanceof NightWerewolf => $this->processNightWerewolf($game),
            $phase instanceof NightSeer => $this->processNightSeer($game),
            $phase instanceof NightDoctor => $this->processNightDoctor($game),
            $phase instanceof Dawn => $this->processDawn($game),
            $phase instanceof DayDiscussion => $this->processDayDiscussion($game),
            $phase instanceof DayVoting => $this->processDayVoting($game),
            $phase instanceof Dusk => $this->processDusk($game),
            default => null,
        };
    }

    protected function processNightWerewolf(Game $game): void
    {
        $werewolves = $game->alivePlayers()
            ->whereIn('role', [GameRole::Werewolf->value])
            ->get();

        // All werewolves vote on a target, majority wins
        $targets = [];

        foreach ($werewolves as $werewolf) {
            $context = $this->gameContext->buildForPlayer($game, $werewolf);
            $role = $this->roleRegistry->get($werewolf->role);

            $result = NightActionAgent::make(
                player: $werewolf,
                game: $game,
                context: $context,
                actionPrompt: $role->nightActionPrompt(),
            )->prompt(
                'Choose your target for tonight.',
                provider: $werewolf->provider,
                model: $werewolf->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game);
            $targets[] = $targetId;

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'werewolf_kill',
                'actor_player_id' => $werewolf->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $result['public_reasoning'] ?? '',
                ],
                'is_public' => false,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));
        }

        // Use the majority target (or first if no majority)
        $finalTarget = collect($targets)
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        // If multiple werewolves, consolidate to a single kill event
        if ($werewolves->count() > 1) {
            // Delete individual actions, create consolidated one
            $game->events()
                ->where('round', $game->round)
                ->where('type', 'werewolf_kill')
                ->update(['target_player_id' => $finalTarget]);
        }

        $game->phase->transitionTo(NightSeer::class);
        $this->broadcastPhaseChange($game);
    }

    protected function processNightSeer(Game $game): void
    {
        $seer = $game->alivePlayers()
            ->where('role', GameRole::Seer->value)
            ->first();

        if ($seer) {
            $context = $this->gameContext->buildForPlayer($game, $seer);
            $role = $this->roleRegistry->get($seer->role);

            $result = NightActionAgent::make(
                player: $seer,
                game: $game,
                context: $context,
                actionPrompt: $role->nightActionPrompt(),
            )->prompt(
                'Choose a player to investigate.',
                provider: $seer->provider,
                model: $seer->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game);
            $target = Player::find($targetId);

            // Get investigation result
            $investigationResult = $target
                ? $role->describeNightResult($seer, $target, $game)
                : 'The investigation revealed nothing.';

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'seer_investigate',
                'actor_player_id' => $seer->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $result['public_reasoning'] ?? '',
                    'result' => $investigationResult,
                ],
                'is_public' => false,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));
        }

        $game->phase->transitionTo(NightDoctor::class);
        $this->broadcastPhaseChange($game);
    }

    protected function processNightDoctor(Game $game): void
    {
        $doctor = $game->alivePlayers()
            ->where('role', GameRole::Doctor->value)
            ->first();

        if ($doctor) {
            $context = $this->gameContext->buildForPlayer($game, $doctor);
            $role = $this->roleRegistry->get($doctor->role);

            $result = NightActionAgent::make(
                player: $doctor,
                game: $game,
                context: $context,
                actionPrompt: $role->nightActionPrompt(),
            )->prompt(
                'Choose a player to protect tonight.',
                provider: $doctor->provider,
                model: $doctor->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game);

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'doctor_protect',
                'actor_player_id' => $doctor->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $result['public_reasoning'] ?? '',
                ],
                'is_public' => false,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));
        }

        $game->phase->transitionTo(Dawn::class);
        $this->broadcastPhaseChange($game);
    }

    protected function processDawn(Game $game): void
    {
        $result = $this->nightResolver->resolve($game);

        // Broadcast death events
        foreach ($result['events'] as $event) {
            if ($event->type === 'death') {
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $event->target_player_id,
                    $event->data['role_revealed'] ?? 'unknown',
                ));
            } else {
                broadcast(new PlayerActed($game->id, $event->toData()));
            }
        }

        // Check win condition
        if ($this->checkWinCondition($game)) {
            return;
        }

        $game->phase->transitionTo(DayDiscussion::class);
        $this->broadcastPhaseChange($game);
    }

    protected function processDayDiscussion(Game $game): void
    {
        $alivePlayers = $game->alivePlayers()->get();

        foreach ($alivePlayers as $player) {
            $context = $this->gameContext->buildForPlayer($game, $player);

            $result = DiscussionAgent::make(
                player: $player,
                game: $game,
                context: $context,
            )->prompt(
                'Share your thoughts with the group. Who do you suspect and why?',
                provider: $player->provider,
                model: $player->model,
            );

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'discussion',
                'actor_player_id' => $player->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'message' => $result['message'] ?? (string) $result,
                ],
                'is_public' => true,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));

            // Small delay between player speeches
            sleep(1);
        }

        $game->phase->transitionTo(DayVoting::class);
        $this->broadcastPhaseChange($game);
    }

    protected function processDayVoting(Game $game): void
    {
        $alivePlayers = $game->alivePlayers()->get();

        foreach ($alivePlayers as $player) {
            $context = $this->gameContext->buildForPlayer($game, $player);

            $result = VoteAgent::make(
                player: $player,
                game: $game,
                context: $context,
            )->prompt(
                'Vote for a player to eliminate.',
                provider: $player->provider,
                model: $player->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game);

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'vote',
                'actor_player_id' => $player->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $result['public_reasoning'] ?? '',
                ],
                'is_public' => true,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));
        }

        $game->phase->transitionTo(Dusk::class);
        $this->broadcastPhaseChange($game);
    }

    protected function processDusk(Game $game): void
    {
        $result = $this->voteResolver->resolve($game);

        // Broadcast events
        foreach ($result['events'] as $event) {
            if ($event->type === 'elimination') {
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $event->target_player_id,
                    $event->data['role_revealed'] ?? 'unknown',
                ));
            } else {
                broadcast(new PlayerActed($game->id, $event->toData()));
            }
        }

        // Check win condition
        if ($this->checkWinCondition($game)) {
            return;
        }

        // Start next round
        $game->update(['round' => $game->round + 1]);
        $game->phase->transitionTo(NightWerewolf::class);
        $this->broadcastPhaseChange($game);
    }

    /**
     * Check if the game has ended.
     * Returns true if a winner was determined.
     */
    protected function checkWinCondition(Game $game): bool
    {
        $game->refresh();
        $alive = $game->alivePlayers()->get();

        $werewolvesAlive = $alive->filter(
            fn (Player $p) => $p->role === GameRole::Werewolf
        )->count();

        $villagersAlive = $alive->filter(
            fn (Player $p) => $p->role !== GameRole::Werewolf
        )->count();

        $winner = null;
        $message = '';

        if ($werewolvesAlive === 0) {
            $winner = GameTeam::Village;
            $message = 'All werewolves have been eliminated! The village wins!';
        } elseif ($werewolvesAlive >= $villagersAlive) {
            $winner = GameTeam::Werewolves;
            $message = 'The werewolves have taken over the village! Werewolves win!';
        }

        if ($winner) {
            $game->update(['winner' => $winner]);
            $game->phase->transitionTo(GameOver::class);
            $game->status->transitionTo(Finished::class);

            $game->events()->create([
                'round' => $game->round,
                'phase' => 'game_over',
                'type' => 'game_end',
                'data' => [
                    'winner' => $winner->value,
                    'message' => $message,
                ],
                'is_public' => true,
            ]);

            broadcast(new GameEnded($game->id, $winner->value, $message));
            broadcast(new GamePhaseChanged($game->id, 'game_over', $game->round, $message));

            return true;
        }

        return false;
    }

    /**
     * Distribute roles based on player count.
     * 2 werewolves, 1 seer, 1 doctor, rest villagers.
     *
     * @return GameRole[]
     */
    protected function distributeRoles(int $playerCount): array
    {
        $roles = [
            GameRole::Werewolf,
            GameRole::Werewolf,
            GameRole::Seer,
            GameRole::Doctor,
        ];

        // Fill remaining with villagers
        $villagersNeeded = $playerCount - count($roles);
        for ($i = 0; $i < $villagersNeeded; $i++) {
            $roles[] = GameRole::Villager;
        }

        return $roles;
    }

    /**
     * Resolve a target_id from AI output to a valid alive player ID.
     */
    protected function resolveTargetId(mixed $targetId, Game $game): ?int
    {
        $targetId = (int) $targetId;

        // Verify the target is a valid alive player in this game
        $valid = $game->alivePlayers()->where('id', $targetId)->exists();

        if (! $valid) {
            // Fall back to a random alive player
            $fallback = $game->alivePlayers()->inRandomOrder()->first();

            return $fallback?->id;
        }

        return $targetId;
    }

    protected function broadcastPhaseChange(Game $game): void
    {
        $game->refresh();
        $phase = $game->phase;

        broadcast(new GamePhaseChanged(
            $game->id,
            $phase->getValue(),
            $game->round,
            $phase->description(),
        ));
    }
}
