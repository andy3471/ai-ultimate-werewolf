<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunGame implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 1800;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    public function __construct(
        public Game $game,
    ) {}

    /**
     * The unique ID of the job (prevents duplicate runs for the same game).
     */
    public function uniqueId(): string
    {
        return $this->game->id;
    }

    public function handle(GameEngine $engine): void
    {
        $engine->startGame($this->game);
        $engine->run($this->game);
    }
}
