<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;
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
}
