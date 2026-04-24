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

    public function nightActionPipelineOrder(): ?int
    {
        return 20;
    }

    public function nightActionPrompt(): string
    {
        return <<<'PROMPT'
        You are the Seer. It is night time and you may investigate one player to learn their true allegiance.
        Choose a player you are suspicious of. You will learn whether they are a Werewolf or a Villager (aligned with the village).
        Use this information wisely during the day — but be careful about revealing yourself, or the werewolves will target you.
        Important: Do NOT infer role from model/provider/name metadata. Those are not game signals.
        If evidence is weak (especially on night 1), make an uncertainty-aware pick rather than overconfident certainty.
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
        Never treat a player's model name, provider name, or formatting style as evidence of role alignment.
        INSTRUCTIONS;
    }

    public function rulesPrompt(): string
    {
        return 'Seer (Village team): Investigates one player each night and learns whether they are Werewolves-aligned or Village-aligned. Wins when all werewolves are eliminated.';
    }

    public function secretKnowledge(Game $game, Player $player): string
    {
        $investigations = $game->events()
            ->where('actor_player_id', $player->id)
            ->where('type', 'seer_investigate')
            ->get();

        if ($investigations->isEmpty()) {
            return '';
        }

        $lines = ['## Your Investigation Results'];

        foreach ($investigations as $event) {
            $result = $event->data['result'] ?? 'Unknown';
            $lines[] = "- Round {$event->round}: {$result}";
        }

        return implode("\n", $lines);
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

        $requestedTargetId = (int) ($result['target_id'] ?? 0);
        $targetId = $context->engine->resolveTargetId($requestedTargetId, $context->game, excludePlayerId: $seer->id);

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
                'requested_target_id' => $requestedTargetId,
                'resolved_target_id' => $targetId,
            ],
            'is_public' => false,
        ]);

        broadcast(new PlayerActed($context->game->id, $event->toData()));

        return RoleActionResult::continue();
    }

    public function standardDeckCopies(int $playerCount): int
    {
        return 1;
    }

    public function standardDeckCompositionOrder(): int
    {
        return 20;
    }
}
