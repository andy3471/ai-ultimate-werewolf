<?php

namespace App\Game\RoleExecution;

use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;

class RoleExecutionContext
{
    public function __construct(
        public Game $game,
        public GameEngine $engine,
        public ?Player $actor = null,
    ) {}
}
