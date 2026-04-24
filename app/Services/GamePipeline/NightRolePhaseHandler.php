<?php

namespace App\Services\GamePipeline;

use App\Contracts\Game\PhaseHandler;
use App\Enums\GameRole;
use App\Models\Game;
use App\Services\GameEngine;
use App\Services\GameSteps\NightRoleStepRunner;

class NightRolePhaseHandler implements PhaseHandler
{
    public function __construct(
        protected string $phaseClass,
        protected GameRole $roleId,
        protected string $nextPhaseClass,
        protected NightRoleStepRunner $nightRoleStepRunner,
        protected GameEngine $engine,
        protected bool $narrateNextPhase = true,
    ) {}

    public function supports(Game $game): bool
    {
        return $game->phase instanceof $this->phaseClass;
    }

    public function run(Game $game): bool
    {
        return $this->nightRoleStepRunner->run(
            game: $game,
            engine: $this->engine,
            roleId: $this->roleId,
            nextPhaseClass: $this->nextPhaseClass,
            narrateNextPhase: $this->narrateNextPhase,
        );
    }
}
