<?php

namespace App\States\GamePhase;

class Night extends GamePhaseState
{
    public static $name = 'night';

    public function label(): string
    {
        return 'Night';
    }

    public function description(): string
    {
        return 'Night actions are being resolved.';
    }

    public function isNight(): bool
    {
        return true;
    }
}
