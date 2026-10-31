<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;

abstract class Role
{
    abstract public function id(): GameRole;

    abstract public function name(): string;

    abstract public function team(): GameTeam;

    abstract public function baseInstructions(): string;

    public function rulesPrompt(): string
    {
        return '';
    }

    public function secretKnowledge(Game $game, Player $player): string
    {
        return '';
    }

    public function hasNightAction(): bool
    {
        return false;
    }

    public function nightActionPipelineOrder(): ?int
    {
        return null;
    }

    public function nightActionPrompt(): string
    {
        return '';
    }

    public function describeNightResult(Player $actor, Player $target, Game $game): ?string
    {
        return null;
    }

    public function maxPerGame(): int
    {
        return 1;
    }

    public function onNightAction(RoleExecutionContext $context): void {}

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

    public function onElimination(Game $game, Player $eliminated, GameEngine $engine): void {}

    /**
     * @return array{broadcast_message: string, message: string, team: GameTeam}|null
     */
    public function villageEliminationWinOutcome(Game $game, Player $eliminated): ?array
    {
        return null;
    }

    public function pendingEliminationFollowUp(Game $game, Player $eliminated): bool
    {
        return false;
    }

    /**
     * @return array{kill_target?: string|null, protect_target?: string|null}
     */
    public function readNightResolutionContribution(Game $game, int $round): array
    {
        return [];
    }

    public function standardDeckCopies(int $playerCount): int
    {
        return 0;
    }

    public function standardDeckCompositionOrder(): int
    {
        return 100;
    }

    public function gameOverVoiceWinnerAddendum(GameTeam $winner): ?string
    {
        return null;
    }
}
