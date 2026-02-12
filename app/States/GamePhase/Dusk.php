<?php

namespace App\States\GamePhase;

class Dusk extends GamePhaseState
{
    public static $name = 'dusk';

    public function label(): string
    {
        return 'Dusk';
    }

    public function description(): string
    {
        return 'The vote is resolved and elimination is announced.';
    }
}
