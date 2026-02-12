<?php

namespace App\States\GameStatus;

class Finished extends GameStatusState
{
    public static $name = 'finished';

    public function label(): string
    {
        return 'Finished';
    }
}
