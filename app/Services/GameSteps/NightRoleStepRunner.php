<?php

namespace App\Services\GameSteps;

use App\Enums\GameRole;
use App\Models\Game;
use App\Services\GameEngine;
use App\Services\RoleRegistry;

class NightRoleStepRunner
{
    public function __construct(
        protected RoleRegistry $roleRegistry,
    ) {}

    public function run(
        Game $game,
        GameEngine $engine,
        GameRole $roleId,
        string $nextPhaseClass,
        bool $narrateNextPhase = true,
    ): bool {
        $role = $this->roleRegistry->get($roleId);

        if ($role->skipNightPhase($game)) {
            $engine->transitionToPhase($game, $nextPhaseClass, narrate: $narrateNextPhase);

            return true;
        }

        $actors = collect($role->nightActors($game))->values();
        if ($actors->isEmpty()) {
            $engine->transitionToPhase($game, $nextPhaseClass, narrate: $narrateNextPhase);

            return true;
        }

        if (! $role->requiresAllActorsBeforeResolve()) {
            if ($game->phase_step > 0) {
                return true;
            }

            $engine->executeRoleNightAction($game, $roleId, $actors->first());
            $role->resolveNightPhase($game);
            $engine->transitionToPhase($game, $nextPhaseClass, narrate: $narrateNextPhase);

            return true;
        }

        if ($game->phase_step < $actors->count()) {
            $engine->executeRoleNightAction($game, $roleId, $actors->get($game->phase_step));

            return false;
        }

        $role->resolveNightPhase($game);
        $engine->transitionToPhase($game, $nextPhaseClass, narrate: $narrateNextPhase);

        return true;
    }
}
