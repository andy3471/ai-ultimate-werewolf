<?php

namespace App\States\GamePhase;

class Dawn extends GamePhaseState
{
    public static $name = 'dawn';

    public function label(): string
    {
        return 'Dawn';
    }

    public function description(): string
    {
        return 'Night actions are resolved and deaths are announced.';
    }
}
