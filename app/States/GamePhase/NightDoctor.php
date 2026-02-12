<?php

namespace App\States\GamePhase;

class NightDoctor extends GamePhaseState
{
    public static $name = 'night_doctor';

    public function label(): string
    {
        return 'Night - Doctor';
    }

    public function description(): string
    {
        return 'The Doctor chooses a player to protect.';
    }

    public function isNight(): bool
    {
        return true;
    }
}
