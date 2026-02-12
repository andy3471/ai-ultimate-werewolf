<?php

namespace App\States\GamePhase;

class NightSeer extends GamePhaseState
{
    public static $name = 'night_seer';

    public function label(): string
    {
        return 'Night - Seer';
    }

    public function description(): string
    {
        return 'The Seer investigates a player.';
    }

    public function isNight(): bool
    {
        return true;
    }
}
