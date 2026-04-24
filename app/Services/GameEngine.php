<?php

namespace App\Services;

use App\Ai\Agents\DiscussionAgent;
use App\Ai\Agents\NightActionAgent;
use App\Ai\Context\GameContext;
use App\Enums\GameRole;
use App\Events\GamePhaseChanged;
use App\Events\PlayerActed;
use App\Events\PlayerEliminated;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Services\GamePipeline\PhasePipelineBuilder;
use App\Services\GameSteps\DayDiscussionStepRunner;
use App\Services\GameSteps\DayVotingStepRunner;
use App\Services\RoleActions\NightRoleActionService;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Dusk;
use App\States\GamePhase\GameOver;
use App\States\GamePhase\NightBodyguard;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;
use App\States\GameStatus\Finished;
use App\States\GameStatus\Running;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GameEngine
{
    protected const PHASE_DELAY_SECONDS = 2;

    protected const MAX_ROUNDS = 20;

    public function __construct(
        protected RoleRegistry $roleRegistry,
        protected NightResolver $nightResolver,
        protected VoteResolver $voteResolver,
        protected GameContext $gameContext,
        protected VoiceService $voiceService,
        protected DayDiscussionStepRunner $dayDiscussionStepRunner,
        protected DayVotingStepRunner $dayVotingStepRunner,
        protected DayActionService $dayActionService,
        protected PhasePipelineBuilder $phasePipelineBuilder,
        protected NightRoleActionService $nightRoleActionService,
        protected WinConditionResolver $winConditionResolver,
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

        // Determine role distribution (scales with player count)
        $roles = $this->distributeRoles($playerCount);
        $roleDistribution = $this->buildRoleDistribution($roles);

        // Shuffle and assign
        $shuffledRoles = collect($roles)->shuffle();

        foreach ($players as $index => $player) {
            $player->update(['role' => $shuffledRoles[$index]]);
        }

        // Assign voices for TTS
        $this->voiceService->assignVoices($game);

        // Transition game state
        $game->status->transitionTo(Running::class);
        $game->update(['round' => 1, 'phase_step' => 0, 'role_distribution' => $roleDistribution]);
        $game->phase->transitionTo(NightWerewolf::class);

        $this->broadcastPhaseChange($game);
    }

    /**
     * Execute one game step and return next-step metadata.
     *
     * @return array{delay_seconds: int}
     */
    public function runStep(Game $game): array
    {
        $this->pendingDelaySeconds = 0;
        $game->refresh();

        if (
            $game->phase instanceof GameOver
            || $game->status instanceof Finished
            || $game->round > self::MAX_ROUNDS
        ) {
            return ['delay_seconds' => 0];
        }

        try {
            $phaseTransitioned = $this->phasePipelineBuilder
                ->build($game, $this)
                ->run($game);

            if (! $phaseTransitioned) {
                $this->advancePhaseStep($game);
            }
        } catch (\Throwable $e) {
            Log::error('GameEngine error', [
                'game_id' => $game->id,
                'phase' => $game->phase->getValue(),
                'round' => $game->round,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'error',
                'data' => ['message' => 'An error occurred: '.$e->getMessage()],
                'is_public' => true,
            ]);
        }

        return ['delay_seconds' => max(self::PHASE_DELAY_SECONDS, $this->pendingDelaySeconds)];
    }

    public function runCurrentPhase(Game $game): int
    {
        return $this->runStep($game)['delay_seconds'];
    }

    public function maxRounds(): int
    {
        return self::MAX_ROUNDS;
    }

    public function runNightWerewolfStep(Game $game): bool
    {
        if ($game->round === 1) {
            $this->transitionToPhase($game, NightSeer::class);

            return true;
        }

        $werewolves = $game->alivePlayers()
            ->where('role', GameRole::Werewolf->value)
            ->get()
            ->values();

        if ($werewolves->isEmpty()) {
            $this->transitionToPhase($game, NightSeer::class);

            return true;
        }

        if ($game->phase_step < $werewolves->count()) {
            $werewolf = $werewolves->get($game->phase_step);
            $this->executeRoleNightAction($game, GameRole::Werewolf, $werewolf);

            return false;
        }

        $this->nightRoleActionService->resolveWerewolfTarget($game);
        $this->transitionToPhase($game, NightSeer::class);

        return true;
    }

    public function runNightSeerStep(Game $game): bool
    {
        if ($game->phase_step > 0) {
            return true;
        }

        $this->executeRoleNightAction($game, GameRole::Seer);
        $this->transitionToPhase($game, NightBodyguard::class);

        return true;
    }

    public function runNightBodyguardStep(Game $game): bool
    {
        if ($game->round === 1) {
            $this->transitionToPhase($game, Dawn::class, narrate: false);

            return true;
        }

        if ($game->phase_step > 0) {
            return true;
        }

        $this->executeRoleNightAction($game, GameRole::Bodyguard);
        $this->transitionToPhase($game, Dawn::class, narrate: false);

        return true;
    }

    public function runDawnStep(Game $game): bool
    {
        $resolution = $this->getOrCreateDawnResolution($game);
        $dawnEvents = $game->events()
            ->where('round', $game->round)
            ->where('phase', Dawn::$name)
            ->whereIn('type', ['death', 'bodyguard_save', 'no_death'])
            ->orderBy('id')
            ->get()
            ->values();

        if ($game->phase_step < $dawnEvents->count()) {
            $event = $dawnEvents->get($game->phase_step);

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

            $this->addDelaySeconds(2);

            return false;
        }

        $stepAfterBroadcasts = (int) $dawnEvents->count();
        if ($game->phase_step === $stepAfterBroadcasts) {
            $this->generateAndBroadcastNarration($game);

            return false;
        }

        $killedPlayerId = $resolution['killed_player_id'] ?? null;
        $killedPlayer = $killedPlayerId ? Player::find($killedPlayerId) : null;

        if ($killedPlayer) {
            if ($game->phase_step === $stepAfterBroadcasts + 1) {
                $this->giveDyingSpeech($game, $killedPlayer);

                return false;
            }

            if ($game->phase_step === $stepAfterBroadcasts + 2) {
                $this->processHunterRevengeShot($game, $killedPlayer);

                return false;
            }

            if ($game->phase_step === $stepAfterBroadcasts + 3) {
                $shotEvent = $game->events()
                    ->where('round', $game->round)
                    ->where('phase', $game->phase->getValue())
                    ->where('type', 'hunter_shot')
                    ->latest('id')
                    ->first();

                if ($shotEvent && $shotEvent->target_player_id) {
                    $shotPlayer = Player::find($shotEvent->target_player_id);
                    if ($shotPlayer) {
                        $this->giveDyingSpeech($game, $shotPlayer);
                    }
                }

                return false;
            }
        }

        if ($this->checkWinCondition($game)) {
            return true;
        }

        $this->transitionToPhase($game, DayDiscussion::class);

        return true;
    }

    public function runDayDiscussionStep(Game $game): bool
    {
        return $this->dayDiscussionStepRunner->run($game, $this);
    }

    public function runDayVotingStep(Game $game): bool
    {
        return $this->dayVotingStepRunner->run($game, $this);
    }

    public function runDuskStep(Game $game): bool
    {
        if ($game->phase_step > 0) {
            return true;
        }

        $this->processDusk($game);

        return true;
    }

    protected function executeRoleNightAction(Game $game, GameRole $roleId, ?Player $actor = null): void
    {
        $role = $this->roleRegistry->get($roleId);
        $context = new RoleExecutionContext(
            game: $game,
            engine: $this,
            actor: $actor,
        );

        $role->validateAction($context);
        $role->onNightAction($context);
    }

    // =========================================================================
    // Day Phases — legacy wrappers
    // =========================================================================

    /**
     * Dynamic discussion: opening round + open-floor with directed questions.
     *
     * 1. Round 1: Every alive player speaks once (randomized order). They may
     *    address a question to a specific player.
     * 2. Open floor: Up to (playerCount * 2) additional turns. If someone was
     *    addressed, they get priority next. Otherwise, weighted-random pick
     *    (players who have spoken less get higher weight). Players may pass.
     * 3. Stops early if all remaining players pass consecutively.
     *
     * Total budget: playerCount * 2 statements (max 3 per player).
     */
    protected function processDayDiscussion(Game $game): void
    {
        $safetyCounter = 0;

        while (! ($game->phase instanceof DayVoting) && $safetyCounter < 200) {
            $transitioned = $this->dayDiscussionStepRunner->run($game, $this);
            if ($transitioned) {
                break;
            }

            $this->advancePhaseStep($game);
            $game->refresh();
            $safetyCounter++;
        }
    }

    /**
     * Record a discussion event in the database, with optional TTS audio.
     */
    public function recordDiscussionEvent(Game $game, Player $player, string $message, mixed $result, ?string $addressedId): GameEvent
    {
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'discussion',
            'actor_player_id' => $player->id,
            'target_player_id' => $addressedId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'message' => $message,
                'addressed_player_id' => $addressedId,
            ],
            'is_public' => true,
        ]);

        $this->generateAndAttachAudio($event, $player, $message);

        return $event;
    }

    /**
     * Duration of the last generated audio clip (seconds), or 0.
     */
    protected float $lastAudioDuration = 0;

    /**
     * Total seconds to wait before running the next phase job.
     */
    protected int $pendingDelaySeconds = 0;

    /**
     * Generate TTS audio for an event and attach the URL.
     * Stores the real audio duration for waitForAudio().
     */
    public function generateAndAttachAudio(GameEvent $event, Player $player, string $text): void
    {
        $this->lastAudioDuration = 0;

        $result = $this->voiceService->generateSpeech($player, $text, $event->id);

        if ($result) {
            $event->update(['audio_url' => $result['url']]);
            $this->lastAudioDuration = $result['duration'];
        }
    }

    /**
     * Accumulate a delay long enough for frontend audio playback.
     */
    public function waitForAudio(): void
    {
        if ($this->lastAudioDuration > 0) {
            $delay = (int) ceil($this->lastAudioDuration) + 2;
            $this->addDelaySeconds($delay);
        } elseif ($this->voiceService->isAvailable()) {
            $this->addDelaySeconds(4);
        } else {
            $this->addDelaySeconds(1);
        }

        $this->lastAudioDuration = 0;
    }

    /**
     * Resolve a player number (1-based order) from AI output to the real player UUID.
     * Returns null if the player is not alive, is self, or the number is invalid.
     */
    protected function resolvePlayerNumberToId(mixed $number, Game $game): ?string
    {
        $number = (int) $number;

        if ($number <= 0) {
            return null;
        }

        // Player numbers are 1-based (order + 1)
        $player = $game->players()->where('order', $number - 1)->first();

        return $player?->id;
    }

    /**
     * Validate an addressed_player_id from AI output (a player number, not a UUID).
     * Returns null if 0 or not a valid alive player (or is self).
     */
    public function resolveAddressedPlayerId(mixed $number, Game $game, string $selfId): ?string
    {
        $id = $this->resolvePlayerNumberToId($number, $game);

        if (! $id || $id === $selfId) {
            return null;
        }

        if ($game->alivePlayers()->where('id', $id)->exists()) {
            return $id;
        }

        return null;
    }

    /**
     * Nomination → Defense → Trial vote (Ultimate Werewolf style).
     *
     * 1. Each player nominates someone for elimination
     * 2. The most-nominated player goes on trial
     * 3. The accused makes a defense speech
     * 4. Village votes yes (eliminate) or no (spare)
     * 5. Majority required for elimination
     */
    protected function processDayVoting(Game $game): void
    {
        $safetyCounter = 0;

        while (! ($game->phase instanceof Dusk) && $safetyCounter < 300) {
            $transitioned = $this->dayVotingStepRunner->run($game, $this);
            if ($transitioned) {
                break;
            }

            $this->advancePhaseStep($game);
            $game->refresh();
            $safetyCounter++;
        }
    }

    // =========================================================================
    // Dusk — wrap up the day, start next round
    // =========================================================================

    protected function processDusk(Game $game): void
    {
        // Check win condition
        if ($this->checkWinCondition($game)) {
            return;
        }

        // Start next round
        $game->update(['round' => $game->round + 1]);
        $this->transitionToPhase($game, NightWerewolf::class);
    }

    // =========================================================================
    // Dying speech
    // =========================================================================

    /**
     * Give an eliminated player their last words.
     */
    public function giveDyingSpeech(Game $game, Player $player): void
    {
        try {
            $context = $this->gameContext->buildForPlayer($game, $player);
            $context .= "\n\n## YOUR FINAL WORDS\nYou have been eliminated from the game. Your role was {$player->role->value}. You may now give your dying speech — your last chance to influence the game.";

            $result = DiscussionAgent::make(
                player: $player,
                game: $game,
                context: $context,
            )->prompt(
                'You have been eliminated. Give your dying speech — your last words to the village.',
                provider: $player->provider,
                model: $player->model,
            );

            $message = $result['message'] ?? (string) $result;

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'dying_speech',
                'actor_player_id' => $player->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'message' => $message,
                ],
                'is_public' => true,
            ]);

            $this->generateAndAttachAudio($event, $player, $message);

            broadcast(new PlayerActed($game->id, $event->toData()));

            $this->waitForAudio();
        } catch (\Throwable $e) {
            Log::warning('Failed to get dying speech', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Hunter revenge kill
    // =========================================================================

    /**
     * If the eliminated player is the Hunter, they take someone down with them.
     */
    protected function handleHunterRevenge(Game $game, Player $deadPlayer): void
    {
        $this->processHunterRevengeShot($game, $deadPlayer);

        $shotEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'hunter_shot')
            ->latest('id')
            ->first();

        if (! $shotEvent?->target_player_id) {
            return;
        }

        $shotPlayer = Player::find($shotEvent->target_player_id);
        if ($shotPlayer) {
            $this->giveDyingSpeech($game, $shotPlayer);
        }
    }

    // =========================================================================
    // Win condition
    // =========================================================================

    /**
     * Check win conditions including Tanner special win.
     *
     * @param  Player|null  $eliminatedByVillage  If set, the player just voted out (check Tanner win)
     */
    public function checkWinCondition(Game $game, ?Player $eliminatedByVillage = null): bool
    {
        $resolved = $this->winConditionResolver->resolve($game, $eliminatedByVillage);
        if ($resolved) {
            $this->broadcastPhaseChange($game);
        }

        return $resolved;
    }

    // =========================================================================
    // Role distribution (scales with player count)
    // =========================================================================

    /**
     * Distribute roles based on player count (Ultimate Werewolf scaling).
     * - 5-6 players: 1 werewolf
     * - 7-11 players: 2 werewolves
     * - 12+ players: 3 werewolves
     * Always: 1 Seer, 1 Bodyguard, 1 Hunter.
     * Tanner added at 7+ players.
     * Rest are Villagers.
     *
     * @return GameRole[]
     */
    protected function distributeRoles(int $playerCount): array
    {
        $werewolfCount = match (true) {
            $playerCount <= 6 => 1,
            $playerCount <= 11 => 2,
            default => 3,
        };

        $roles = [];

        for ($i = 0; $i < $werewolfCount; $i++) {
            $roles[] = GameRole::Werewolf;
        }

        $roles[] = GameRole::Seer;
        $roles[] = GameRole::Bodyguard;
        $roles[] = GameRole::Hunter;

        // Tanner at 7+ players (adds a wild card)
        if ($playerCount >= 7) {
            $roles[] = GameRole::Tanner;
        }

        // Fill remaining with villagers
        $villagersNeeded = $playerCount - count($roles);
        for ($i = 0; $i < $villagersNeeded; $i++) {
            $roles[] = GameRole::Villager;
        }

        return $roles;
    }

    /**
     * Build a human-readable role distribution map (e.g. {"Werewolf": 2, "Seer": 1, ...}).
     *
     * @param  GameRole[]  $roles
     * @return array<string, int>
     */
    protected function buildRoleDistribution(array $roles): array
    {
        $distribution = [];

        foreach ($roles as $role) {
            $name = $this->roleRegistry->get($role)->name();
            $distribution[$name] = ($distribution[$name] ?? 0) + 1;
        }

        return $distribution;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve a target_id (player number) from AI output to a valid alive player UUID.
     * Falls back to a random alive player if the number is invalid.
     */
    public function resolveTargetId(mixed $playerNumber, Game $game, ?string $excludePlayerId = null): ?string
    {
        $targetId = $this->resolvePlayerNumberToId($playerNumber, $game);

        if ($targetId) {
            // Verify the target is a valid alive player in this game
            $query = $game->alivePlayers()->where('id', $targetId);

            if ($excludePlayerId) {
                $query->where('id', '!=', $excludePlayerId);
            }

            if ($query->exists()) {
                return $targetId;
            }
        }

        // Fall back to a random alive player (excluding self if needed)
        $fallbackQuery = $game->alivePlayers();
        if ($excludePlayerId) {
            $fallbackQuery->where('id', '!=', $excludePlayerId);
        }

        return $fallbackQuery->inRandomOrder()->first()?->id;
    }

    protected function broadcastPhaseChange(Game $game, bool $narrate = true): void
    {
        $game->refresh();
        $phase = $game->phase;

        $narrationText = null;
        $narrationAudioUrl = null;

        // Generate AI narration for key phases (can be deferred for phases
        // like dawn where we need to resolve events first)
        if ($narrate) {
            $narration = $this->voiceService->narrate($game, $phase);

            if ($narration) {
                $narrationText = $narration['text'];
                $narrationAudioUrl = $narration['url'];

                // Record narration as a game event (visible in log)
                $game->events()->create([
                    'round' => $game->round,
                    'phase' => $phase->getValue(),
                    'type' => 'narration',
                    'data' => [
                        'message' => $narrationText,
                    ],
                    'audio_url' => $narrationAudioUrl,
                    'is_public' => true,
                ]);

                $this->lastAudioDuration = $narration['duration'];
            }
        }

        broadcast(new GamePhaseChanged(
            $game->id,
            $phase->getValue(),
            $game->round,
            $phase->description(),
            narration: $narrationText,
            narration_audio_url: $narrationAudioUrl,
        ));

        // Wait for narrator audio to finish before proceeding
        if ($narrationText) {
            $this->waitForAudio();
        }
    }

    /**
     * Generate narration for the current phase and broadcast it separately.
     * Used when narration must be deferred (e.g. dawn — after night resolution).
     */
    protected function generateAndBroadcastNarration(Game $game): void
    {
        $game->refresh();
        $phase = $game->phase;

        $narration = $this->voiceService->narrate($game, $phase);

        if (! $narration) {
            return;
        }

        $game->events()->create([
            'round' => $game->round,
            'phase' => $phase->getValue(),
            'type' => 'narration',
            'data' => [
                'message' => $narration['text'],
            ],
            'audio_url' => $narration['url'],
            'is_public' => true,
        ]);

        // Broadcast an updated phase change with the narration attached
        broadcast(new GamePhaseChanged(
            $game->id,
            $phase->getValue(),
            $game->round,
            $phase->description(),
            narration: $narration['text'],
            narration_audio_url: $narration['url'],
        ));

        $this->lastAudioDuration = $narration['duration'];
        $this->waitForAudio();
    }

    protected function advancePhaseStep(Game $game): void
    {
        $game->update(['phase_step' => $game->phase_step + 1]);
    }

    public function transitionToPhase(Game $game, string $phaseClass, bool $narrate = true): void
    {
        $game->phase->transitionTo($phaseClass);
        $game->update(['phase_step' => 0]);
        $this->broadcastPhaseChange($game, narrate: $narrate);
    }

    /**
     * @param  Collection<int,Player>  $alivePlayers
     * @return array{opening_order: array<int,string>, total_budget:int}
     */
    public function getOrCreateDiscussionPlan(Game $game, Collection $alivePlayers): array
    {
        return $this->dayActionService->getOrCreateDiscussionPlan($game, $alivePlayers);
    }

    public function createDiscussionMessage(Game $game, Player $speaker, string $prompt): void
    {
        $this->dayActionService->createDiscussionMessage($game, $speaker, $prompt, $this);
    }

    public function createNomination(Game $game, Player $player): void
    {
        $this->dayActionService->createNomination($game, $player, $this);
    }

    public function createNominationResult(Game $game, Collection $alivePlayers): ?Player
    {
        return $this->dayActionService->createNominationResult($game, $alivePlayers);
    }

    public function createDefenseSpeech(Game $game, Player $accused): void
    {
        $this->dayActionService->createDefenseSpeech($game, $accused, $this);
    }

    public function createTrialVote(Game $game, Player $voter, Player $accused): void
    {
        $this->dayActionService->createTrialVote($game, $voter, $accused, $this);
    }

    /**
     * @return array{eliminated_id: string|null}
     */
    public function createTrialOutcome(Game $game, Player $accused): array
    {
        return $this->dayActionService->createTrialOutcome($game, $accused, $this);
    }

    /**
     * @return array{killed_player_id: string|null}
     */
    protected function getOrCreateDawnResolution(Game $game): array
    {
        $resolutionEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'dawn_resolution')
            ->latest('id')
            ->first();

        if ($resolutionEvent) {
            return $resolutionEvent->data;
        }

        $result = $this->nightResolver->resolve($game);
        $resolution = [
            'killed_player_id' => $result['killed']?->id,
        ];

        $created = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'dawn_resolution',
            'data' => $resolution,
            'is_public' => false,
        ]);

        return $created->data;
    }

    public function processHunterRevengeShot(Game $game, Player $deadPlayer): void
    {
        if ($deadPlayer->role !== GameRole::Hunter) {
            return;
        }

        try {
            $context = $this->gameContext->buildForPlayer($game, $deadPlayer);
            $context .= "\n\n## HUNTER'S REVENGE\nYou have been eliminated, but as the Hunter, you get to take one player down with you! Choose wisely — target who you believe is a werewolf.";

            $result = NightActionAgent::make(
                player: $deadPlayer,
                game: $game,
                context: $context,
                actionPrompt: 'You are the Hunter. Choose one alive player to shoot with your dying action. Pick who you believe is a werewolf.',
            )->prompt(
                'Choose a player to take down with you.',
                provider: $deadPlayer->provider,
                model: $deadPlayer->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $deadPlayer->id);
            $target = $targetId ? Player::find($targetId) : null;

            if ($target && $target->is_alive) {
                $target->update(['is_alive' => false]);

                $hunterMessage = "{$deadPlayer->name} was the Hunter and shoots {$target->name} with their dying breath! {$target->name} was a {$target->role->value}.";
                $event = $game->events()->create([
                    'round' => $game->round,
                    'phase' => $game->phase->getValue(),
                    'type' => 'hunter_shot',
                    'actor_player_id' => $deadPlayer->id,
                    'target_player_id' => $target->id,
                    'data' => [
                        'thinking' => $result['thinking'] ?? '',
                        'public_reasoning' => $result['public_reasoning'] ?? '',
                        'message' => $hunterMessage,
                        'role_revealed' => $target->role->value,
                    ],
                    'is_public' => true,
                ]);

                $this->generateAndAttachAudio($event, $deadPlayer, $hunterMessage);
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $target->id,
                    $target->role->value,
                ));
                $this->waitForAudio();
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to process Hunter revenge', [
                'player_id' => $deadPlayer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function addDelaySeconds(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $this->pendingDelaySeconds += $seconds;
    }
}
