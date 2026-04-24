<?php

namespace App\Services\GamePipeline;

use App\Contracts\Game\PhaseHandler;
use App\Models\Game;
use App\Services\GameEngine;

class RunnerDelegatingPhaseHandler implements PhaseHandler
{
    public function __construct(
        protected string $phaseClass,
        protected object $runner,
        protected GameEngine $engine,
    ) {}

    public function supports(Game $game): bool
    {
        return $game->phase instanceof $this->phaseClass;
    }

    public function run(Game $game): bool
    {
        return $this->runner->run($game, $this->engine);
    }
}
