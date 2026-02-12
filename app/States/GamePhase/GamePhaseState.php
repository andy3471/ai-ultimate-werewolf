<?php

namespace App\States\GamePhase;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class GamePhaseState extends State
{
    abstract public function label(): string;

    abstract public function description(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Lobby::class)
            ->allowTransition(Lobby::class, NightWerewolf::class)
            ->allowTransition(NightWerewolf::class, NightSeer::class)
            ->allowTransition(NightSeer::class, NightBodyguard::class)
            ->allowTransition(NightBodyguard::class, Dawn::class)
            ->allowTransition(Dawn::class, DayDiscussion::class)
            ->allowTransition(Dawn::class, GameOver::class)
            ->allowTransition(DayDiscussion::class, DayVoting::class)
            ->allowTransition(DayVoting::class, Dusk::class)
            ->allowTransition(Dusk::class, NightWerewolf::class)
            ->allowTransition(Dusk::class, GameOver::class);
    }

    public function getValue(): string
    {
        return $this::getMorphClass();
    }

    public function isNight(): bool
    {
        return false;
    }

    public function isDay(): bool
    {
        return false;
    }
}
