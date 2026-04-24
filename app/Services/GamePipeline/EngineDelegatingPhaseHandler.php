<?php

namespace App\Services\GamePipeline;

use App\Contracts\Game\PhaseHandler;
use App\Models\Game;
use App\Services\GameEngine;

class EngineDelegatingPhaseHandler implements PhaseHandler
{
    /**
     * @param  class-string  $phaseClass
     */
    public function __construct(
        protected string $phaseClass,
        protected GameEngine $engine,
        protected string $engineMethod,
    ) {}

    public function supports(Game $game): bool
    {
        return $game->phase instanceof $this->phaseClass;
    }

    public function run(Game $game): bool
    {
        return $this->engine->{$this->engineMethod}($game);
    }
}
