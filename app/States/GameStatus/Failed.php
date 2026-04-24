<?php

namespace App\States\GameStatus;

class Failed extends GameStatusState
{
    public static $name = 'failed';

    public function label(): string
    {
        return 'Failed';
    }
}
