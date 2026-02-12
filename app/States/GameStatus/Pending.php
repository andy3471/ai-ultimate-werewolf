<?php

namespace App\States\GameStatus;

class Pending extends GameStatusState
{
    public static $name = 'pending';

    public function label(): string
    {
        return 'Pending';
    }
}
