<?php

namespace App\Services;

use App\Ai\Context\GameContext;
use App\Enums\GameRole;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Services\GamePipeline\PhasePipelineBuilder;
use App\Services\GameSteps\DawnStepRunner;
use App\Services\GameSteps\DayDiscussionStepRunner;
use App\Services\GameSteps\DayVotingStepRunner;
use App\Services\RoleActions\NightRoleActionService;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Dusk;
use App\States\GamePhase\GameOver;
use App\States\GamePhase\NightBodyguard;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;
use App\States\GameStatus\Finished;
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
        protected NarrationAudioService $narrationAudioService,
        protected EliminationService $eliminationService,
        protected GameSetupService $gameSetupService,
        protected DawnStepRunner $dawnStepRunner,
        protected DayDiscussionStepRunner $dayDiscussionStepRunner,
        protected DayVotingStepRunner $dayVotingStepRunner,
        protected PhasePipelineBuilder $phasePipelineBuilder,
        protected NightRoleActionService $nightRoleActionService,
        protected WinConditionResolver $winConditionResolver,
    ) {}

    public function startGame(Game $game): void
    {
        $this->gameSetupService->start($game);
        $this->broadcastPhaseChange($game);
    }

    /** @return array{delay_seconds: int} */
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
        return $this->dawnStepRunner->run($game, $this);
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

    protected int $pendingDelaySeconds = 0;

    public function generateAndAttachAudio(GameEvent $event, Player $player, string $text): void
    {
        $this->narrationAudioService->generateAndAttachAudio($event, $player, $text);
    }

    public function waitForAudio(): void
    {
        $this->addDelaySeconds($this->narrationAudioService->consumeWaitDelaySeconds());
    }

    protected function resolvePlayerNumberToId(mixed $number, Game $game): ?string
    {
        $number = (int) $number;

        if ($number <= 0) {
            return null;
        }

        $player = $game->players()->where('order', $number - 1)->first();

        return $player?->id;
    }

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

    protected function processDusk(Game $game): void
    {
        if ($this->checkWinCondition($game)) {
            return;
        }

        $game->update(['round' => $game->round + 1]);
        $this->transitionToPhase($game, NightWerewolf::class);
    }

    public function giveDyingSpeech(Game $game, Player $player): void
    {
        $this->eliminationService->giveDyingSpeech($game, $player, $this);
    }

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

    public function checkWinCondition(Game $game, ?Player $eliminatedByVillage = null): bool
    {
        $resolved = $this->winConditionResolver->resolve($game, $eliminatedByVillage);
        if ($resolved) {
            $this->broadcastPhaseChange($game);
        }

        return $resolved;
    }

    public function resolveTargetId(mixed $playerNumber, Game $game, ?string $excludePlayerId = null): ?string
    {
        $targetId = $this->resolvePlayerNumberToId($playerNumber, $game);

        if ($targetId) {
            $query = $game->alivePlayers()->where('id', $targetId);

            if ($excludePlayerId) {
                $query->where('id', '!=', $excludePlayerId);
            }

            if ($query->exists()) {
                return $targetId;
            }
        }

        $fallbackQuery = $game->alivePlayers();
        if ($excludePlayerId) {
            $fallbackQuery->where('id', '!=', $excludePlayerId);
        }

        return $fallbackQuery->inRandomOrder()->first()?->id;
    }

    protected function broadcastPhaseChange(Game $game, bool $narrate = true): void
    {
        $this->addDelaySeconds($this->narrationAudioService->broadcastPhaseChange($game, $narrate));
    }

    public function generateAndBroadcastNarration(Game $game): void
    {
        $this->addDelaySeconds($this->narrationAudioService->generateAndBroadcastNarration($game));
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

    /** @return array{killed_player_id: string|null} */
    public function getOrCreateDawnResolution(Game $game): array
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
        $this->eliminationService->processHunterRevengeShot($game, $deadPlayer, $this);
    }

    public function addDelaySeconds(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $this->pendingDelaySeconds += $seconds;
    }
}
