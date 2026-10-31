<?php

namespace App\Services\GameSteps;

use App\Contracts\Game\PhaseStepRunner;
use App\Enums\DayVotingStage;
use App\Game\DayVotingWindows;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\EliminationService;
use App\Services\GameEngine;
use App\Services\RoleRegistry;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\Dusk;
use Illuminate\Support\Collection;

class DayVotingStepRunner implements PhaseStepRunner
{
    public function __construct(
        protected DayActionService $dayActionService,
        protected EliminationService $eliminationService,
        protected RoleRegistry $roleRegistry,
    ) {}

    public function run(Game $game, GameEngine $engine): bool
    {
        $alivePlayers = $game->alivePlayers()->get()->values();
        $playerCount = $alivePlayers->count();

        if ($playerCount <= 1) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $nominationResult = $this->latestPhaseEvent($game, 'nomination_result');
        $stage = $this->resolveStage($game, $alivePlayers, $nominationResult);

        return match ($stage) {
            DayVotingStage::BuildingNominationResult => $this->buildNominationResult($game, $alivePlayers, $engine),
            DayVotingStage::CollectingNominations => $this->collectNomination($game, $alivePlayers, $engine),
            DayVotingStage::NominationsExhausted => $this->returnToDiscussion($game, $engine),
            DayVotingStage::AwaitingSecondWindow => true,
            DayVotingStage::CollectingSeconds => $this->collectSecond($game, $alivePlayers, $nominationResult, $engine),
            DayVotingStage::SecondsExhausted => $this->returnToDiscussion($game, $engine),
            DayVotingStage::Defense => $this->runDefense($game, $alivePlayers, $nominationResult, $engine),
            DayVotingStage::TrialVote => $this->collectTrialVote($game, $alivePlayers, $nominationResult, $engine),
            DayVotingStage::TrialOutcome => $this->recordTrialOutcome($game, $nominationResult, $engine),
            DayVotingStage::DyingSpeech => $this->runDyingSpeech($game, $nominationResult, $engine),
            DayVotingStage::EliminationFollowUp => $this->runEliminationFollowUp($game, $nominationResult, $engine),
            DayVotingStage::HunterShotDyingSpeech => $this->runHunterShotDyingSpeech($game, $engine),
            DayVotingStage::Complete => $this->finishVoting($game, $alivePlayers, $nominationResult, $engine),
        };
    }

    /**
     * @param  Collection<int, Player>  $alivePlayers
     */
    protected function resolveStage(Game $game, Collection $alivePlayers, ?GameEvent $nominationResult): DayVotingStage
    {
        $playerCount = $alivePlayers->count();

        if (! $nominationResult) {
            $hasNomination = $game->events()
                ->where('round', $game->round)
                ->where('phase', $game->phase->getValue())
                ->where('type', 'nomination')
                ->exists();

            if ($hasNomination) {
                return DayVotingStage::BuildingNominationResult;
            }

            $windows = DayVotingWindows::beforeNominationResult($playerCount);

            if ($windows->isCollectingNominations($game->phase_step)) {
                return DayVotingStage::CollectingNominations;
            }

            return DayVotingStage::NominationsExhausted;
        }

        $accusedId = $nominationResult->target_player_id;
        if (! $accusedId || ! Player::find($accusedId)) {
            return DayVotingStage::Complete;
        }

        $secondEvent = $this->latestPhaseEvent($game, 'nomination_second');
        $windows = DayVotingWindows::afterNominationResult(
            $playerCount,
            (int) ($nominationResult->data['nomination_result_step'] ?? $playerCount),
        );

        if (! $secondEvent) {
            if ($windows->isCollectingSeconds($game->phase_step)) {
                return DayVotingStage::CollectingSeconds;
            }

            if ($windows->isPastSecondWindow($game->phase_step)) {
                return DayVotingStage::SecondsExhausted;
            }

            return DayVotingStage::AwaitingSecondWindow;
        }

        $defenseCount = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'defense_speech')
            ->count();

        if ($defenseCount < 2) {
            return DayVotingStage::Defense;
        }

        $votesCast = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote')
            ->count();

        if ($votesCast < $alivePlayers->count()) {
            return DayVotingStage::TrialVote;
        }

        $trialOutcome = $this->latestPhaseEvent($game, 'vote_outcome');

        if (! $trialOutcome) {
            return DayVotingStage::TrialOutcome;
        }

        $eliminatedId = $trialOutcome->data['eliminated_id'] ?? null;
        $eliminatedPlayer = $eliminatedId ? Player::find($eliminatedId) : null;

        if ($eliminatedPlayer && ! $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'dying_speech')
            ->where('actor_player_id', $eliminatedPlayer->id)
            ->exists()) {
            return DayVotingStage::DyingSpeech;
        }

        if (
            $eliminatedPlayer
            && $this->roleRegistry->get($eliminatedPlayer->role)->pendingEliminationFollowUp($game, $eliminatedPlayer)
        ) {
            return DayVotingStage::EliminationFollowUp;
        }

        if ($eliminatedPlayer && $this->hasPendingHunterShotDyingSpeech($game)) {
            return DayVotingStage::HunterShotDyingSpeech;
        }

        return DayVotingStage::Complete;
    }

    protected function hasPendingHunterShotDyingSpeech(Game $game): bool
    {
        if ($game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'hunter_shot_followup_done')
            ->exists()) {
            return false;
        }

        return $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'hunter_shot')
            ->exists();
    }

    /**
     * @param  Collection<int, Player>  $alivePlayers
     */
    protected function buildNominationResult(Game $game, Collection $alivePlayers, GameEngine $engine): bool
    {
        $result = $this->dayActionService->createNominationResult($game, $alivePlayers);
        if (! $result) {
            $engine->transitionToPhase($game, DayDiscussion::class);

            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, Player>  $alivePlayers
     */
    protected function collectNomination(Game $game, Collection $alivePlayers, GameEngine $engine): bool
    {
        $player = $alivePlayers->get($game->phase_step);
        if ($player) {
            $this->dayActionService->createNomination($game, $player, $engine);
        }

        return false;
    }

    /**
     * @param  Collection<int, Player>  $alivePlayers
     */
    protected function collectSecond(Game $game, Collection $alivePlayers, ?GameEvent $nominationResult, GameEngine $engine): bool
    {
        $accusedId = $nominationResult?->target_player_id;
        $accused = $accusedId ? Player::find($accusedId) : null;
        if (! $accused) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $windows = DayVotingWindows::afterNominationResult(
            $alivePlayers->count(),
            (int) ($nominationResult?->data['nomination_result_step'] ?? $alivePlayers->count()),
        );
        $nominatorId = (string) ($nominationResult?->data['nominator_id'] ?? '');
        $seconder = $alivePlayers->get($windows->seconderIndex($game->phase_step));
        if ($seconder) {
            $this->dayActionService->createNominationSecond($game, $seconder, $accused, $nominatorId, $engine);
        }

        return false;
    }

    /**
     * @param  Collection<int, Player>  $alivePlayers
     */
    protected function runDefense(Game $game, Collection $alivePlayers, ?GameEvent $nominationResult, GameEngine $engine): bool
    {
        $accusedId = $nominationResult?->target_player_id;
        $accused = $accusedId ? Player::find($accusedId) : null;
        if (! $accused) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $defenseCount = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'defense_speech')
            ->count();

        if ($defenseCount === 0) {
            $this->dayActionService->createDefenseSpeech($game, $accused, $engine);
        } else {
            $secondarySpeaker = $alivePlayers
                ->first(fn (Player $player) => $player->id !== $accused->id);
            if ($secondarySpeaker) {
                $this->dayActionService->createDefenseDiscussionMessage(
                    $game,
                    $secondarySpeaker,
                    'Give a brief response to the defense before the vote starts.',
                    $engine,
                );
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Player>  $alivePlayers
     */
    protected function collectTrialVote(Game $game, Collection $alivePlayers, ?GameEvent $nominationResult, GameEngine $engine): bool
    {
        $accusedId = $nominationResult?->target_player_id;
        $accused = $accusedId ? Player::find($accusedId) : null;
        if (! $accused) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $votesCastIds = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote')
            ->pluck('actor_player_id')
            ->filter()
            ->values();

        $voter = $alivePlayers->first(fn (Player $player) => ! $votesCastIds->contains($player->id));
        if ($voter) {
            $this->dayActionService->createTrialVote($game, $voter, $accused, $engine);
        }

        return false;
    }

    protected function recordTrialOutcome(Game $game, ?GameEvent $nominationResult, GameEngine $engine): bool
    {
        $accusedId = $nominationResult?->target_player_id;
        $accused = $accusedId ? Player::find($accusedId) : null;
        if (! $accused) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $this->dayActionService->createTrialOutcome($game, $accused, $engine);

        return false;
    }

    protected function runDyingSpeech(Game $game, ?GameEvent $nominationResult, GameEngine $engine): bool
    {
        $trialOutcome = $this->latestPhaseEvent($game, 'vote_outcome');
        $eliminatedId = $trialOutcome?->data['eliminated_id'] ?? null;
        $eliminatedPlayer = $eliminatedId ? Player::find($eliminatedId) : null;

        if ($eliminatedPlayer) {
            $this->eliminationService->giveDyingSpeech($game, $eliminatedPlayer, $engine);
        }

        return false;
    }

    protected function runEliminationFollowUp(Game $game, ?GameEvent $nominationResult, GameEngine $engine): bool
    {
        $trialOutcome = $this->latestPhaseEvent($game, 'vote_outcome');
        $eliminatedId = $trialOutcome?->data['eliminated_id'] ?? null;
        $eliminatedPlayer = $eliminatedId ? Player::find($eliminatedId) : null;

        if ($eliminatedPlayer) {
            $this->eliminationService->processEliminationFollowUp($game, $eliminatedPlayer, $engine);
        }

        return false;
    }

    protected function runHunterShotDyingSpeech(Game $game, GameEngine $engine): bool
    {
        $this->eliminationService->giveHunterShotDyingSpeech($game, $engine);

        return false;
    }

    /**
     * @param  Collection<int, Player>  $alivePlayers
     */
    protected function finishVoting(Game $game, Collection $alivePlayers, ?GameEvent $nominationResult, GameEngine $engine): bool
    {
        $trialOutcome = $this->latestPhaseEvent($game, 'vote_outcome');
        $eliminatedId = $trialOutcome?->data['eliminated_id'] ?? null;
        $eliminatedPlayer = $eliminatedId ? Player::find($eliminatedId) : null;
        $accusedId = $nominationResult?->target_player_id;
        $accused = $accusedId ? Player::find($accusedId) : null;

        if (! $accused && ! $eliminatedPlayer) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        if ($eliminatedPlayer && $engine->checkWinCondition($game, eliminatedByVillage: $eliminatedPlayer)) {
            return true;
        }

        if (! $eliminatedPlayer && $engine->checkWinCondition($game)) {
            return true;
        }

        if ($eliminatedPlayer) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $this->dayActionService->addDiscussionExtension($game, $alivePlayers->count());
        $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'nomination_block',
            'target_player_id' => $accused?->id,
            'is_public' => false,
        ]);
        $engine->transitionToPhase($game, DayDiscussion::class);

        return true;
    }

    protected function returnToDiscussion(Game $game, GameEngine $engine): bool
    {
        $engine->transitionToPhase($game, DayDiscussion::class);

        return true;
    }

    protected function latestPhaseEvent(Game $game, string $type): ?GameEvent
    {
        return $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', $type)
            ->latest('id')
            ->first();
    }
}
