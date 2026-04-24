<?php

namespace App\Services;

use App\Enums\GameRole;
use App\Models\Game;
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
        $werewolfCount = match (true) {
            $playerCount <= 6 => 1,
            $playerCount <= 11 => 2,
            default => 3,
        };

        $roles = [];

        for ($i = 0; $i < $werewolfCount; $i++) {
            $roles[] = GameRole::Werewolf;
        }

        $roles[] = GameRole::Seer;
        $roles[] = GameRole::Bodyguard;
        $roles[] = GameRole::Hunter;

        if ($playerCount >= 7) {
            $roles[] = GameRole::Tanner;
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
