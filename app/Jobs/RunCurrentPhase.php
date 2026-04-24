<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\GameOver;
use App\States\GameStatus\Failed;
use App\States\GameStatus\Finished;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            Log::warning('phase_job_game_missing', [
                'game_id' => $this->game->id,
                'expected_round' => $this->expectedRound,
                'expected_phase' => $this->expectedPhase,
                'expected_phase_step' => $this->expectedPhaseStep,
            ]);

            return;
        }

        if (
            $game->status instanceof Finished
            || $game->status instanceof Failed
            || $game->phase instanceof GameOver
            || $game->round > $engine->maxRounds()
        ) {
            Log::info('phase_job_terminal_state_noop', [
                'game_id' => $game->id,
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'status' => $game->status->getValue(),
            ]);

            return;
        }

        if (
            $game->round !== $this->expectedRound
            || $game->phase->getValue() !== $this->expectedPhase
            || (int) $game->phase_step !== $this->expectedPhaseStep
        ) {
            Log::info('phase_job_stale_noop', [
                'game_id' => $game->id,
                'actual_round' => $game->round,
                'actual_phase' => $game->phase->getValue(),
                'actual_phase_step' => (int) $game->phase_step,
                'expected_round' => $this->expectedRound,
                'expected_phase' => $this->expectedPhase,
                'expected_phase_step' => $this->expectedPhaseStep,
            ]);

            return;
        }

        Log::info('phase_job_execute', [
            'game_id' => $game->id,
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'phase_step' => (int) $game->phase_step,
        ]);

        $stepResult = $engine->runStep($game);
        $delaySeconds = (int) ($stepResult['delay_seconds'] ?? 0);
        $game->refresh();

        if (
            $delaySeconds <= 0
            || $game->status instanceof Finished
            || $game->status instanceof Failed
            || $game->phase instanceof GameOver
            || $game->round > $engine->maxRounds()
        ) {
            Log::info('phase_job_stop_dispatch', [
                'game_id' => $game->id,
                'delay_seconds' => $delaySeconds,
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'phase_step' => (int) $game->phase_step,
                'status' => $game->status->getValue(),
            ]);

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

    public function failed(?Throwable $exception): void
    {
        $game = $this->game->fresh();

        if (! $game || $game->status instanceof Finished || $game->status instanceof Failed) {
            return;
        }

        $game->status->transitionTo(Failed::class);

        $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'game_failed',
            'is_public' => true,
            'data' => [
                'message' => 'Game execution failed due to a background job error.',
            ],
        ]);

        Log::error('phase_job_failed_marked_game_failed', [
            'game_id' => $game->id,
            'expected_round' => $this->expectedRound,
            'expected_phase' => $this->expectedPhase,
            'expected_phase_step' => $this->expectedPhaseStep,
            'actual_round' => $game->round,
            'actual_phase' => $game->phase->getValue(),
            'actual_phase_step' => (int) $game->phase_step,
            'exception_message' => $exception?->getMessage(),
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
