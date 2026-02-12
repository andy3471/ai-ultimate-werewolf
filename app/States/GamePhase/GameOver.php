<?php

namespace App\States\GamePhase;

class GameOver extends GamePhaseState
{
    public static $name = 'game_over';

    public function label(): string
    {
        return 'Game Over';
    }

    public function description(): string
    {
        return 'The game has ended.';
    }
}
