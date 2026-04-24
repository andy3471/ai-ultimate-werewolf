<?php

namespace App\Services;

use App\Enums\GameRole;
use App\Models\Game;
use App\Roles\Role;
use App\States\GamePhase\Night;
use App\States\GameStatus\Running;

class GameSetupService
{
    public function __construct(
        protected RoleRegistry $roleRegistry,
        protected VoiceService $voiceService,
    ) {}

    public function start(Game $game): void
    {
        $game->refresh();

        if ($game->status instanceof Running) {
            return;
        }

        $players = $game->players()->get();
        $playerCount = $players->count();
        $roles = $this->distributeRoles($playerCount);
        $roleDistribution = $this->buildRoleDistribution($roles);
        $shuffledRoles = collect($roles)->shuffle();

        foreach ($players as $index => $player) {
            $player->update(['role' => $shuffledRoles[$index]]);
        }

        $this->voiceService->assignVoices($game);

        $game->status->transitionTo(Running::class);
        $game->update(['round' => 1, 'phase_step' => 0, 'role_distribution' => $roleDistribution]);
        $game->phase->transitionTo(Night::class);
    }

    /**
     * @return GameRole[]
     */
    protected function distributeRoles(int $playerCount): array
    {
        $roles = [];
        $ordered = collect($this->roleRegistry->all())
            ->sortBy(fn (Role $role) => $role->standardDeckCompositionOrder())
            ->values();

        foreach ($ordered as $role) {
            $copies = $role->standardDeckCopies($playerCount);
            for ($i = 0; $i < $copies; $i++) {
                $roles[] = $role->id();
            }
        }

        $villagersNeeded = $playerCount - count($roles);
        for ($i = 0; $i < $villagersNeeded; $i++) {
            $roles[] = GameRole::Villager;
        }

        return $roles;
    }

    /**
     * @param  GameRole[]  $roles
     * @return array<string, int>
     */
    protected function buildRoleDistribution(array $roles): array
    {
        $distribution = [];

        foreach ($roles as $role) {
            $name = $this->roleRegistry->get($role)->name();
            $distribution[$name] = ($distribution[$name] ?? 0) + 1;
        }

        return $distribution;
    }
}
