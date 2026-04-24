<?php

namespace App\Services\GameSteps;

use App\Events\PlayerActed;
use App\Events\PlayerEliminated;
use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\States\GamePhase\DayDiscussion;

class DawnStepRunner
{
    public function run(Game $game, GameEngine $engine): bool
    {
        $resolution = $engine->getOrCreateDawnResolution($game);
        $dawnEvents = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
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

            $engine->addDelaySeconds(2);

            return false;
        }

        $stepAfterBroadcasts = (int) $dawnEvents->count();
        if ($game->phase_step === $stepAfterBroadcasts) {
            $engine->generateAndBroadcastNarration($game);

            return false;
        }

        $killedPlayerId = $resolution['killed_player_id'] ?? null;
        $killedPlayer = $killedPlayerId ? Player::find($killedPlayerId) : null;

        if ($killedPlayer) {
            if ($game->phase_step === $stepAfterBroadcasts + 1) {
                $engine->giveDyingSpeech($game, $killedPlayer);

                return false;
            }

            if ($game->phase_step === $stepAfterBroadcasts + 2) {
                $engine->processEliminationFollowUp($game, $killedPlayer);

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
                        $engine->giveDyingSpeech($game, $shotPlayer);
                    }
                }

                return false;
            }
        }

        if ($engine->checkWinCondition($game)) {
            return true;
        }

        $engine->transitionToPhase($game, DayDiscussion::class);

        return true;
    }
}
