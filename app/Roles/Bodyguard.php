<?php

namespace App\Roles;

use App\Enums\GameEventType;
use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Models\Game;
use App\Models\Player;
use App\Services\RoleActions\NightActionExecutor;

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

    public function nightActionPipelineOrder(): ?int
    {
        return 30;
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

    public function rulesPrompt(): string
    {
        return 'Bodyguard (Village team): Protects one player each night from werewolf kill. Cannot protect the same player on consecutive nights (self-protection allowed). Wins when all werewolves are eliminated.';
    }

    public function onNightAction(RoleExecutionContext $context): void
    {
        $bodyguard = $context->actor;
        if (! $bodyguard) {
            return;
        }

        $lastProtection = $context->game->events()
            ->where('type', GameEventType::BodyguardProtect->value)
            ->where('actor_player_id', $bodyguard->id)
            ->where('round', $context->game->round - 1)
            ->first();

        $lastProtectedId = $lastProtection?->target_player_id;
        $lastProtectedName = $lastProtectedId
            ? Player::find($lastProtectedId)?->name ?? 'Unknown'
            : null;

        $contextSuffix = $lastProtectedName
            ? "\n\n## Bodyguard Restriction\nYou protected **{$lastProtectedName}** (ID: {$lastProtectedId}) last night. You CANNOT protect them again tonight. You must choose a different player."
            : '';

        app(NightActionExecutor::class)->perform(
            context: $context,
            actionPrompt: $this->nightActionPrompt(),
            userPrompt: 'Choose a player to protect tonight.',
            eventType: GameEventType::BodyguardProtect,
            contextSuffix: $contextSuffix,
            excludeActor: false,
            adjustTargetId: function (?string $targetId) use ($context, $lastProtectedId): ?string {
                if ($targetId === $lastProtectedId) {
                    return $context->game->alivePlayers()
                        ->where('id', '!=', $lastProtectedId)
                        ->inRandomOrder()
                        ->first()?->id;
                }

                return $targetId;
            },
        );
    }

    public function skipNightPhase(Game $game): bool
    {
        return $game->round === 1;
    }

    public function readNightResolutionContribution(Game $game, int $round): array
    {
        $event = $game->events()
            ->where('round', $round)
            ->where('type', GameEventType::BodyguardProtect->value)
            ->first();

        return [
            'protect_target' => $event?->target_player_id,
        ];
    }

    public function standardDeckCopies(int $playerCount): int
    {
        return 1;
    }

    public function standardDeckCompositionOrder(): int
    {
        return 30;
    }
}
