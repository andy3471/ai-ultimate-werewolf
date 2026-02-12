<?php

namespace App\States\GamePhase;

class Lobby extends GamePhaseState
{
    public static $name = 'lobby';

    public function label(): string
    {
        return 'Lobby';
    }

    public function description(): string
    {
        return 'Waiting for the game to start.';
    }
}
