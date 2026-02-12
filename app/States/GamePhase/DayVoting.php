<?php

namespace App\States\GamePhase;

class DayVoting extends GamePhaseState
{
    public static $name = 'day_voting';

    public function label(): string
    {
        return 'Day - Voting';
    }

    public function description(): string
    {
        return 'Players vote to eliminate a suspect.';
    }

    public function isDay(): bool
    {
        return true;
    }
}
