<?php

namespace App\States\GameStatus;

class Running extends GameStatusState
{
    public static $name = 'running';

    public function label(): string
    {
        return 'Running';
    }
}
