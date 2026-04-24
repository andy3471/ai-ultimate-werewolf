<?php

namespace App\Services\GameSteps;

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\EliminationService;
use App\Services\GameEngine;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\Dusk;

class DayVotingStepRunner
{
    public function __construct(
        protected DayActionService $dayActionService,
        protected EliminationService $eliminationService,
    ) {}

    public function run(Game $game, GameEngine $engine): bool
    {
        $alivePlayers = $game->alivePlayers()->get()->values();
        $playerCount = $alivePlayers->count();

        if ($playerCount <= 1) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $nominationResult = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'nomination_result')
            ->latest('id')
            ->first();

        $nominationCutoff = $playerCount;
        if (! $nominationResult) {
            $hasNomination = $game->events()
                ->where('round', $game->round)
                ->where('phase', $game->phase->getValue())
                ->where('type', 'nomination')
                ->exists();

            if ($hasNomination) {
                $result = $this->dayActionService->createNominationResult($game, $alivePlayers);
                if (! $result) {
                    $engine->transitionToPhase($game, DayDiscussion::class);

                    return true;
                }

                return false;
            }

            if ($game->phase_step < $nominationCutoff) {
                $player = $alivePlayers->get($game->phase_step);
                if ($player) {
                    $this->dayActionService->createNomination($game, $player, $engine);
                }

                return false;
            }

            if ($game->phase_step >= $nominationCutoff) {
                $engine->transitionToPhase($game, DayDiscussion::class);

                return true;
            }
        }

        $accusedId = $nominationResult?->target_player_id;
        $nominatorId = (string) ($nominationResult?->data['nominator_id'] ?? '');
        $accused = $accusedId ? Player::find($accusedId) : null;
        if (! $accused) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $secondEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'nomination_second')
            ->latest('id')
            ->first();

        $secondWindowStart = (int) ($nominationResult?->data['nomination_result_step'] ?? $nominationCutoff) + 1;
        $secondWindowEnd = $secondWindowStart + $playerCount;

        if (! $secondEvent) {
            if ($game->phase_step >= $secondWindowStart && $game->phase_step < $secondWindowEnd) {
                $seconder = $alivePlayers->get($game->phase_step - $secondWindowStart);
                if ($seconder) {
                    $this->dayActionService->createNominationSecond($game, $seconder, $accused, $nominatorId, $engine);
                }

                return false;
            }

            if ($game->phase_step >= $secondWindowEnd) {
                $engine->transitionToPhase($game, DayDiscussion::class);
            }

            return true;
        }

        $defenseBudget = 2;
        $defenseCount = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'defense_speech')
            ->count();

        if ($defenseCount < $defenseBudget) {
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

        $voters = $alivePlayers->values();
        $votesCastIds = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote')
            ->pluck('actor_player_id')
            ->filter()
            ->values();

        if ($votesCastIds->count() < $voters->count()) {
            $voter = $voters->first(fn (Player $player) => ! $votesCastIds->contains($player->id));
            if ($voter) {
                $this->dayActionService->createTrialVote($game, $voter, $accused, $engine);
            }

            return false;
        }

        $existingOutcome = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote_outcome')
            ->latest('id')
            ->first();

        if (! $existingOutcome) {
            $this->dayActionService->createTrialOutcome($game, $accused, $engine);

            return false;
        }

        $trialOutcome = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote_outcome')
            ->latest('id')
            ->first();

        $eliminatedId = $trialOutcome?->data['eliminated_id'] ?? null;
        $eliminatedPlayer = $eliminatedId ? Player::find($eliminatedId) : null;

        if ($eliminatedPlayer && ! $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'dying_speech')
            ->where('actor_player_id', $eliminatedPlayer->id)
            ->exists()) {
            $this->eliminationService->giveDyingSpeech($game, $eliminatedPlayer, $engine);

            return false;
        }

        if (
            $eliminatedPlayer
            && $eliminatedPlayer->role === GameRole::Hunter
            && ! $game->events()
                ->where('round', $game->round)
                ->where('phase', $game->phase->getValue())
                ->where('type', 'hunter_shot')
                ->exists()
        ) {
            $this->eliminationService->processEliminationFollowUp($game, $eliminatedPlayer, $engine);

            return false;
        }

        if ($eliminatedPlayer && ! $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'hunter_shot_followup_done')
            ->exists()) {
            $shotEvent = $game->events()
                ->where('round', $game->round)
                ->where('phase', $game->phase->getValue())
                ->where('type', 'hunter_shot')
                ->latest('id')
                ->first();

            if ($shotEvent && $shotEvent->target_player_id) {
                $shotPlayer = Player::find($shotEvent->target_player_id);
                if ($shotPlayer) {
                    $this->eliminationService->giveDyingSpeech($game, $shotPlayer, $engine);
                }
            }

            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'hunter_shot_followup_done',
                'is_public' => false,
            ]);

            return false;
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
            'target_player_id' => $accused->id,
            'is_public' => false,
        ]);
        $engine->transitionToPhase($game, DayDiscussion::class);

        return true;
    }
}
