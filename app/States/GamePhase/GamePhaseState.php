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
            ->allowTransition(Lobby::class, Night::class)
            ->allowTransition(Night::class, Dawn::class)
            ->allowTransition(Dawn::class, DayDiscussion::class)
            ->allowTransition(Dawn::class, GameOver::class)
            ->allowTransition(DayDiscussion::class, DayVoting::class)
            ->allowTransition(DayVoting::class, Dusk::class)
            ->allowTransition(DayVoting::class, GameOver::class)
            ->allowTransition(Dusk::class, Night::class)
            ->allowTransition(Dusk::class, GameOver::class)
            ->allowTransition(Night::class, GameOver::class);
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
