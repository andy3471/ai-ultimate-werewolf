<?php

namespace App\Contracts\Game;

use App\Models\Game;

interface PhaseHandler
{
    public function supports(Game $game): bool;

    public function run(Game $game): bool;
}
