<?php

namespace App\States\GamePhase;

class NightWerewolf extends GamePhaseState
{
    public static $name = 'night_werewolf';

    public function label(): string
    {
        return 'Night - Werewolves';
    }

    public function description(): string
    {
        return 'The werewolves choose their victim.';
    }

    public function isNight(): bool
    {
        return true;
    }
}
