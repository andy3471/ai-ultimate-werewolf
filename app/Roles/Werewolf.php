<?php

namespace App\Roles;

use App\Ai\Agents\NightActionAgent;
use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Events\PlayerActed;
use App\Game\RoleExecution\RoleActionResult;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Models\Game;

class Werewolf extends Role
{
    public function id(): GameRole
    {
        return GameRole::Werewolf;
    }

    public function name(): string
    {
        return 'Werewolf';
    }

    public function team(): GameTeam
    {
        return GameTeam::Werewolves;
    }

    public function hasNightAction(): bool
    {
        return true;
    }

    public function nightActionPrompt(): string
    {
        return <<<'PROMPT'
        You are a Werewolf. It is night time and you must choose a player to kill.
        You know who the other werewolves are — coordinate with them to eliminate villagers strategically.
        Choose a player who you think is dangerous to your team (e.g., the Seer or Doctor).
        You cannot target another werewolf or yourself.
        PROMPT;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are a Werewolf in a game of Ultimate Werewolf. Your goal is to eliminate all villagers without being discovered.
        During the day, you must blend in with the villagers and deflect suspicion away from yourself and your fellow werewolves.
        You can accuse others, defend yourself, and try to manipulate nominations and votes to eliminate villagers.
        At night, you and your fellow werewolves coordinate to choose a victim to eliminate.
        Remember: if the villagers discover you, they will nominate and vote to eliminate you.
        The village uses a nomination → trial → vote system. Players nominate suspects, the most-nominated goes on trial, and a majority vote is needed to eliminate.
        INSTRUCTIONS;
    }

    public function maxPerGame(): int
    {
        return 2;
    }

    public function onNightAction(RoleExecutionContext $context): RoleActionResult
    {
        $werewolf = $context->actor;
        if (! $werewolf) {
            return RoleActionResult::continue();
        }

        $gameContext = app(\App\Ai\Context\GameContext::class)->buildForPlayer($context->game, $werewolf);

        $result = NightActionAgent::make(
            player: $werewolf,
            game: $context->game,
            context: $gameContext,
            actionPrompt: $this->nightActionPrompt(),
        )->prompt(
            'Choose your target for tonight.',
            provider: $werewolf->provider,
            model: $werewolf->model,
        );

        $targetId = $context->engine->resolveTargetId($result['target_id'], $context->game, excludePlayerId: $werewolf->id);

        $event = $context->game->events()->create([
            'round' => $context->game->round,
            'phase' => $context->game->phase->getValue(),
            'type' => 'werewolf_kill',
            'actor_player_id' => $werewolf->id,
            'target_player_id' => $targetId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $result['public_reasoning'] ?? '',
            ],
            'is_public' => false,
        ]);

        broadcast(new PlayerActed($context->game->id, $event->toData()));

        return RoleActionResult::continue();
    }

    public function skipNightPhase(Game $game): bool
    {
        return $game->round === 1;
    }

    public function resolveNightPhase(Game $game): void
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

    public function requiresAllActorsBeforeResolve(): bool
    {
        return true;
    }
}
