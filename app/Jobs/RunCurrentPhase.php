<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\GameOver;
use App\States\GameStatus\Finished;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunCurrentPhase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    public function __construct(
        public Game $game,
        public int $expectedRound,
        public string $expectedPhase,
        public int $expectedPhaseStep,
    ) {}

    /**
     * Prevent concurrent phase processing for the same game.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('game-phase:'.$this->game->id))
                ->dontRelease()
                ->expireAfter(300),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(GameEngine $engine): void
    {
        $game = $this->game->fresh();

        if (! $game) {
            return;
        }

        if (
            $game->status instanceof Finished
            || $game->phase instanceof GameOver
            || $game->round > $engine->maxRounds()
        ) {
            return;
        }

        if (
            $game->round !== $this->expectedRound
            || $game->phase->getValue() !== $this->expectedPhase
            || (int) $game->phase_step !== $this->expectedPhaseStep
        ) {
            return;
        }

        $stepResult = $engine->runStep($game);
        $delaySeconds = (int) ($stepResult['delay_seconds'] ?? 0);
        $game->refresh();

        if (
            $delaySeconds <= 0
            || $game->status instanceof Finished
            || $game->phase instanceof GameOver
            || $game->round > $engine->maxRounds()
        ) {
            return;
        }

        self::dispatch(
            $game,
            (int) $game->round,
            $game->phase->getValue(),
            (int) $game->phase_step,
        )
            ->delay(now()->addSeconds($delaySeconds));
    }
}
