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
use App\Services\RoleRegistry;

class Seer extends Role
{
    public function id(): GameRole
    {
        return GameRole::Seer;
    }

    public function name(): string
    {
        return 'Seer';
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
        You are the Seer. It is night time and you may investigate one player to learn their true allegiance.
        Choose a player you are suspicious of. You will learn whether they are a Werewolf or a Villager (aligned with the village).
        Use this information wisely during the day — but be careful about revealing yourself, or the werewolves will target you.
        PROMPT;
    }

    public function describeNightResult(Player $actor, Player $target, Game $game): ?string
    {
        $targetRole = app(RoleRegistry::class)->get($target->role);

        return $target->name.' is aligned with the '.($targetRole->team() === GameTeam::Werewolves ? 'Werewolves' : 'Village').'.';
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are the Seer in a game of Ultimate Werewolf. Your goal is to identify the werewolves and help the village eliminate them.
        Each night, you can investigate one player to learn their true allegiance (Werewolf or Village).
        During the day, you must decide how to use this information. You can share it openly, hint at it subtly,
        or keep it secret to avoid being targeted by the werewolves. Balance information sharing with self-preservation.
        The village uses a nomination → trial → vote system. Help guide nominations toward confirmed werewolves.
        INSTRUCTIONS;
    }

    public function onNightAction(RoleExecutionContext $context): RoleActionResult
    {
        $seer = $context->actor;
        if (! $seer) {
            return RoleActionResult::continue();
        }

        $gameContext = app(\App\Ai\Context\GameContext::class)->buildForPlayer($context->game, $seer);

        $result = NightActionAgent::make(
            player: $seer,
            game: $context->game,
            context: $gameContext,
            actionPrompt: $this->nightActionPrompt(),
        )->prompt(
            'Choose a player to investigate.',
            provider: $seer->provider,
            model: $seer->model,
        );

        $targetId = $context->engine->resolveTargetId($result['target_id'], $context->game, excludePlayerId: $seer->id);
        $target = $targetId ? Player::find($targetId) : null;
        $investigationResult = $target
            ? $this->describeNightResult($seer, $target, $context->game)
            : 'The investigation revealed nothing.';

        $event = $context->game->events()->create([
            'round' => $context->game->round,
            'phase' => $context->game->phase->getValue(),
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

        broadcast(new PlayerActed($context->game->id, $event->toData()));

        return RoleActionResult::continue();
    }
}
