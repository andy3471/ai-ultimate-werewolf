<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Game\RoleExecution\RoleActionResult;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Game\RoleExecution\ValidationResult;
use App\Models\Game;
use App\Models\Player;

abstract class Role
{
    abstract public function id(): GameRole;

    abstract public function name(): string;

    abstract public function team(): GameTeam;

    abstract public function baseInstructions(): string;

    /**
     * The GamePhase state class this role acts in at night, or null if no night action.
     *
     * @return class-string|null
     */
    public function nightPhase(): ?string
    {
        return null;
    }

    /**
     * AI prompt instructions for the night action.
     */
    public function nightActionPrompt(): string
    {
        return '';
    }

    /**
     * Describe the result of a night action to the acting player.
     * For example, the Seer learns whether a target is a werewolf.
     */
    public function describeNightResult(Player $actor, Player $target, Game $game): ?string
    {
        return null;
    }

    /**
     * Maximum number of this role allowed in a single game.
     */
    public function maxPerGame(): int
    {
        return 1;
    }

    public function onNightAction(RoleExecutionContext $context): RoleActionResult
    {
        return RoleActionResult::continue();
    }

    public function skipNightPhase(Game $game): bool
    {
        return false;
    }

    /**
     * @return array<int, Player>
     */
    public function nightActors(Game $game): array
    {
        return $game->alivePlayers()
            ->where('role', $this->id()->value)
            ->get()
            ->values()
            ->all();
    }

    public function resolveNightPhase(Game $game): void {}

    public function requiresAllActorsBeforeResolve(): bool
    {
        return false;
    }

    public function onDawn(RoleExecutionContext $context): ?RoleActionResult
    {
        return null;
    }

    public function onDay(RoleExecutionContext $context): ?RoleActionResult
    {
        return null;
    }

    public function validateAction(RoleExecutionContext $context): ValidationResult
    {
        return ValidationResult::valid();
    }
}
