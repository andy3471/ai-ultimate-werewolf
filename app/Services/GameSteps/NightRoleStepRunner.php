<?php

namespace App\Services\GameSteps;

use App\Models\Game;
use App\Roles\Role;
use App\Services\GameEngine;
use App\Services\RoleRegistry;
use App\States\GamePhase\Dawn;

class NightRoleStepRunner
{
    public function __construct(
        protected RoleRegistry $roleRegistry,
    ) {}

    public function run(Game $game, GameEngine $engine): bool
    {
        $slots = [];
        $nightRoles = collect($this->roleRegistry->all())
            ->filter(fn (Role $role) => $role->hasNightAction() && $role->nightActionPipelineOrder() !== null)
            ->sortBy(fn (Role $role) => $role->nightActionPipelineOrder())
            ->map(fn (Role $role) => $role->id())
            ->values();

        foreach ($nightRoles as $roleId) {
            $role = $this->roleRegistry->get($roleId);

            if ($role->skipNightPhase($game)) {
                continue;
            }

            $actors = collect($role->nightActors($game))->values();
            if ($actors->isEmpty()) {
                continue;
            }

            if ($role->requiresAllActorsBeforeResolve()) {
                foreach ($actors as $actor) {
                    $slots[] = function () use ($engine, $game, $roleId, $actor): void {
                        $engine->executeRoleNightAction($game, $roleId, $actor);
                    };
                }

                $slots[] = function () use ($role, $game): void {
                    $role->resolveNightPhase($game);
                };

                continue;
            }

            $slots[] = function () use ($engine, $game, $roleId, $role, $actors): void {
                $engine->executeRoleNightAction($game, $roleId, $actors->first());
                $role->resolveNightPhase($game);
            };
        }

        if ($slots === []) {
            $engine->transitionToPhase($game, Dawn::class, narrate: false);

            return true;
        }

        $step = (int) $game->phase_step;
        if ($step < count($slots)) {
            $slots[$step]();

            if ($step === count($slots) - 1) {
                $engine->transitionToPhase($game, Dawn::class, narrate: false);

                return true;
            }

            return false;
        }

        $engine->transitionToPhase($game, Dawn::class, narrate: false);

        return true;
    }
}
