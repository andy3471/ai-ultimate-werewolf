<?php

namespace App\Roles;

use App\Ai\Agents\NightActionAgent;
use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Events\PlayerActed;
use App\Game\RoleExecution\RoleActionResult;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Models\Game;
use App\Models\Player;

class Bodyguard extends Role
{
    public function id(): GameRole
    {
        return GameRole::Bodyguard;
    }

    public function name(): string
    {
        return 'Bodyguard';
    }

    public function team(): GameTeam
    {
        return GameTeam::Village;
    }

    public function hasNightAction(): bool
    {
        return true;
    }

    public function nightActionPrompt(): string
    {
        return <<<'PROMPT'
        You are the Bodyguard. It is night time and you may protect one player from being killed by the werewolves.
        Choose a player you think the werewolves might target. If the werewolves target the player you protect, they will survive.
        You may also choose to protect yourself.
        IMPORTANT: You CANNOT protect the same player two nights in a row. If you protected someone last night, you must choose a different player tonight.
        PROMPT;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are the Bodyguard in a game of Ultimate Werewolf. Your goal is to protect villagers from werewolf attacks.
        Each night, you can choose one player to protect. If the werewolves target that player, they will survive.
        CRITICAL RULE: You cannot protect the same player two consecutive nights. You must switch your protection each night.
        During the day, be careful about revealing your role — if the werewolves know who you are, they may try to eliminate you.
        Use your protection wisely: protect players who seem valuable to the village (like a suspected Seer).
        INSTRUCTIONS;
    }

    public function onNightAction(RoleExecutionContext $context): RoleActionResult
    {
        $bodyguard = $context->actor;
        if (! $bodyguard) {
            return RoleActionResult::continue();
        }

        $lastProtection = $context->game->events()
            ->where('type', 'bodyguard_protect')
            ->where('actor_player_id', $bodyguard->id)
            ->where('round', $context->game->round - 1)
            ->first();

        $lastProtectedId = $lastProtection?->target_player_id;
        $lastProtectedName = $lastProtectedId
            ? Player::find($lastProtectedId)?->name ?? 'Unknown'
            : null;

        $gameContext = app(\App\Ai\Context\GameContext::class)->buildForPlayer($context->game, $bodyguard);
        if ($lastProtectedName) {
            $gameContext .= "\n\n## Bodyguard Restriction\nYou protected **{$lastProtectedName}** (ID: {$lastProtectedId}) last night. You CANNOT protect them again tonight. You must choose a different player.";
        }

        $result = NightActionAgent::make(
            player: $bodyguard,
            game: $context->game,
            context: $gameContext,
            actionPrompt: $this->nightActionPrompt(),
        )->prompt(
            'Choose a player to protect tonight.',
            provider: $bodyguard->provider,
            model: $bodyguard->model,
        );

        $targetId = $context->engine->resolveTargetId($result['target_id'], $context->game);

        if ($targetId === $lastProtectedId) {
            $targetId = $context->game->alivePlayers()
                ->where('id', '!=', $lastProtectedId)
                ->inRandomOrder()
                ->first()?->id;
        }

        $event = $context->game->events()->create([
            'round' => $context->game->round,
            'phase' => $context->game->phase->getValue(),
            'type' => 'bodyguard_protect',
            'actor_player_id' => $bodyguard->id,
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
}
