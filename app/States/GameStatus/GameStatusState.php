<?php

namespace App\States\GameStatus;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class GameStatusState extends State
{
    abstract public function label(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Running::class)
            ->allowTransition(Pending::class, Failed::class)
            ->allowTransition(Running::class, Finished::class)
            ->allowTransition(Running::class, Failed::class);
    }

    public function getValue(): string
    {
        return $this::getMorphClass();
    }
}
