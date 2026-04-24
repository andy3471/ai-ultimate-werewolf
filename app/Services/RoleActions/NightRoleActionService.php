<?php

namespace App\Services\RoleActions;

use App\Ai\Agents\NightActionAgent;
use App\Ai\Context\GameContext;
use App\Enums\GameRole;
use App\Events\PlayerActed;
use App\Models\Game;
use App\Models\Player;
use App\Services\RoleRegistry;

class NightRoleActionService
{
    public function __construct(
        protected RoleRegistry $roleRegistry,
        protected GameContext $gameContext,
    ) {}

    public function processWerewolfProposal(Game $game, Player $werewolf, string $phase): void
    {
        $context = $this->gameContext->buildForPlayer($game, $werewolf);
        $role = $this->roleRegistry->get($werewolf->role);

        $result = NightActionAgent::make(
            player: $werewolf,
            game: $game,
            context: $context,
            actionPrompt: $role->nightActionPrompt(),
        )->prompt(
            'Choose your target for tonight.',
            provider: $werewolf->provider,
            model: $werewolf->model,
        );

        $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $werewolf->id);

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $phase,
            'type' => 'werewolf_kill',
            'actor_player_id' => $werewolf->id,
            'target_player_id' => $targetId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $result['public_reasoning'] ?? '',
            ],
            'is_public' => false,
        ]);

        broadcast(new PlayerActed($game->id, $event->toData()));
    }

    public function resolveWerewolfTarget(Game $game): void
    {
        $events = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'werewolf_kill')
            ->orderBy('id')
            ->get();

        $finalTarget = $events
            ->pluck('target_player_id')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'werewolf_kill')
            ->update(['target_player_id' => $finalTarget]);
    }

    public function processSeer(Game $game, string $phase): void
    {
        $seer = $game->alivePlayers()
            ->where('role', GameRole::Seer->value)
            ->first();

        if (! $seer) {
            return;
        }

        $context = $this->gameContext->buildForPlayer($game, $seer);
        $role = $this->roleRegistry->get($seer->role);

        $result = NightActionAgent::make(
            player: $seer,
            game: $game,
            context: $context,
            actionPrompt: $role->nightActionPrompt(),
        )->prompt(
            'Choose a player to investigate.',
            provider: $seer->provider,
            model: $seer->model,
        );

        $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $seer->id);
        $target = $targetId ? Player::find($targetId) : null;

        $investigationResult = $target
            ? $role->describeNightResult($seer, $target, $game)
            : 'The investigation revealed nothing.';

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $phase,
            'type' => 'seer_investigate',
            'actor_player_id' => $seer->id,
            'target_player_id' => $targetId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $result['public_reasoning'] ?? '',
                'result' => $investigationResult,
            ],
            'is_public' => false,
        ]);

        broadcast(new PlayerActed($game->id, $event->toData()));
    }

    public function processBodyguard(Game $game, string $phase): void
    {
        $bodyguard = $game->alivePlayers()
            ->where('role', GameRole::Bodyguard->value)
            ->first();

        if (! $bodyguard) {
            return;
        }

        $lastProtection = $game->events()
            ->where('type', 'bodyguard_protect')
            ->where('actor_player_id', $bodyguard->id)
            ->where('round', $game->round - 1)
            ->first();

        $lastProtectedId = $lastProtection?->target_player_id;
        $lastProtectedName = $lastProtectedId
            ? Player::find($lastProtectedId)?->name ?? 'Unknown'
            : null;

        $context = $this->gameContext->buildForPlayer($game, $bodyguard);

        if ($lastProtectedName) {
            $context .= "\n\n## Bodyguard Restriction\nYou protected **{$lastProtectedName}** (ID: {$lastProtectedId}) last night. You CANNOT protect them again tonight. You must choose a different player.";
        }

        $role = $this->roleRegistry->get($bodyguard->role);

        $result = NightActionAgent::make(
            player: $bodyguard,
            game: $game,
            context: $context,
            actionPrompt: $role->nightActionPrompt(),
        )->prompt(
            'Choose a player to protect tonight.',
            provider: $bodyguard->provider,
            model: $bodyguard->model,
        );

        $targetId = $this->resolveTargetId($result['target_id'], $game);

        if ($targetId === $lastProtectedId) {
            $targetId = $game->alivePlayers()
                ->where('id', '!=', $lastProtectedId)
                ->inRandomOrder()
                ->first()?->id;
        }

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $phase,
            'type' => 'bodyguard_protect',
            'actor_player_id' => $bodyguard->id,
            'target_player_id' => $targetId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $result['public_reasoning'] ?? '',
            ],
            'is_public' => false,
        ]);

        broadcast(new PlayerActed($game->id, $event->toData()));
    }

    protected function resolveTargetId(mixed $playerNumber, Game $game, ?string $excludePlayerId = null): ?string
    {
        if (! is_numeric($playerNumber)) {
            return null;
        }

        $index = (int) $playerNumber - 1;
        if ($index < 0) {
            return null;
        }

        $alive = $game->alivePlayers()->get()->values();
        $target = $alive->get($index);

        if (! $target) {
            return null;
        }

        if ($excludePlayerId !== null && $target->id === $excludePlayerId) {
            return null;
        }

        return $target->id;
    }
}
