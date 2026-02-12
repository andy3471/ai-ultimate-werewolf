<?php

namespace App\States\GamePhase;

class NightBodyguard extends GamePhaseState
{
    public static $name = 'night_bodyguard';

    public function label(): string
    {
        return 'Night - Bodyguard';
    }

    public function description(): string
    {
        return 'The Bodyguard chooses a player to protect.';
    }

    public function isNight(): bool
    {
        return true;
    }
}
