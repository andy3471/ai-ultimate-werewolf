<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunGame implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    public function __construct(
        public Game $game,
    ) {}

    public function handle(GameEngine $engine): void
    {
        $engine->startGame($this->game);
        $engine->run($this->game);
    }
}
