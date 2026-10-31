<?php

namespace App\Services\GameSteps;

use App\Contracts\Game\PhaseStepRunner;
use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\Night;

class DuskStepRunner implements PhaseStepRunner
{
    public function run(Game $game, GameEngine $engine): bool
    {
        if ($game->phase_step > 0) {
            return true;
        }

        if ($engine->checkWinCondition($game)) {
            return true;
        }

        $game->update(['round' => $game->round + 1]);
        $engine->transitionToPhase($game, Night::class);

        return true;
    }
}
