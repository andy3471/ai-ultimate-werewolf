<?php

namespace App\States\GamePhase;

class DayDiscussion extends GamePhaseState
{
    public static $name = 'day_discussion';

    public function label(): string
    {
        return 'Day - Discussion';
    }

    public function description(): string
    {
        return 'Players discuss and share suspicions.';
    }

    public function isDay(): bool
    {
        return true;
    }
}
