<?php

namespace App\Services\GameSteps;

use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\States\GamePhase\Dusk;

class DayVotingStepRunner
{
    public function run(Game $game, GameEngine $engine): bool
    {
        $alivePlayers = $game->alivePlayers()->get()->values();
        $playerCount = $alivePlayers->count();

        if ($playerCount <= 1) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $nominationCutoff = $playerCount;
        if ($game->phase_step < $nominationCutoff) {
            $player = $alivePlayers->get($game->phase_step);
            if ($player) {
                $engine->createNomination($game, $player);
            }

            return false;
        }

        if ($game->phase_step === $nominationCutoff) {
            $accused = $engine->createNominationResult($game, $alivePlayers);
            if (! $accused) {
                $engine->transitionToPhase($game, Dusk::class);

                return true;
            }

            return false;
        }

        $nominationResult = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'nomination_result')
            ->latest('id')
            ->first();

        $accusedId = $nominationResult?->target_player_id;
        $accused = $accusedId ? Player::find($accusedId) : null;
        if (! $accused) {
            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        if ($game->phase_step === $nominationCutoff + 1) {
            $engine->createDefenseSpeech($game, $accused);

            return false;
        }

        $voters = $alivePlayers
            ->filter(fn (Player $player) => $player->id !== $accused->id)
            ->values();
        $voteStart = $nominationCutoff + 2;
        $voteEnd = $voteStart + $voters->count();

        if ($game->phase_step >= $voteStart && $game->phase_step < $voteEnd) {
            $voter = $voters->get($game->phase_step - $voteStart);
            if ($voter) {
                $engine->createTrialVote($game, $voter, $accused);
            }

            return false;
        }

        if ($game->phase_step === $voteEnd) {
            $outcome = $engine->createTrialOutcome($game, $accused);
            if (($outcome['eliminated_id'] ?? null) !== null) {
                return false;
            }

            $engine->transitionToPhase($game, Dusk::class);

            return true;
        }

        $trialOutcome = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote_outcome')
            ->latest('id')
            ->first();

        $eliminatedId = $trialOutcome?->data['eliminated_id'] ?? null;
        $eliminatedPlayer = $eliminatedId ? Player::find($eliminatedId) : null;
        $postOutcomeStep = $voteEnd + 1;

        if ($eliminatedPlayer && $game->phase_step === $postOutcomeStep) {
            $engine->giveDyingSpeech($game, $eliminatedPlayer);

            return false;
        }

        if ($eliminatedPlayer && $game->phase_step === $postOutcomeStep + 1) {
            $engine->processHunterRevengeShot($game, $eliminatedPlayer);

            return false;
        }

        if ($eliminatedPlayer && $game->phase_step === $postOutcomeStep + 2) {
            $shotEvent = $game->events()
                ->where('round', $game->round)
                ->where('phase', $game->phase->getValue())
                ->where('type', 'hunter_shot')
                ->latest('id')
                ->first();

            if ($shotEvent && $shotEvent->target_player_id) {
                $shotPlayer = Player::find($shotEvent->target_player_id);
                if ($shotPlayer) {
                    $engine->giveDyingSpeech($game, $shotPlayer);
                }
            }

            return false;
        }

        if ($eliminatedPlayer && $engine->checkWinCondition($game, eliminatedByVillage: $eliminatedPlayer)) {
            return true;
        }

        if (! $eliminatedPlayer && $engine->checkWinCondition($game)) {
            return true;
        }

        $engine->transitionToPhase($game, Dusk::class);

        return true;
    }
}
