<?php

namespace App\Contracts\Game;

use App\Models\Game;
use App\Services\GameEngine;

interface PhaseStepRunner
{
    public function run(Game $game, GameEngine $engine): bool;
}
