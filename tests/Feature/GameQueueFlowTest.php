<?php

use App\Jobs\RunCurrentPhase;
use App\Jobs\RunGame;
use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\GameOver;
use App\States\GamePhase\Night;
use App\States\GameStatus\Failed;
use App\States\GameStatus\Finished;
use App\States\GameStatus\Running;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

test('RunGame dispatches the first phase runner after bootstrap', function () {
    Queue::fake();

    $game = Game::factory()->create();
    $engine = \Mockery::mock(GameEngine::class);

    $engine->shouldReceive('startGame')
        ->once()
        ->with(\Mockery::on(fn (Game $startedGame) => $startedGame->is($game)))
        ->andReturnUsing(function (Game $startedGame): void {
            $startedGame->update([
                'status' => Running::getMorphClass(),
                'phase' => Night::getMorphClass(),
                'round' => 1,
                'phase_step' => 0,
            ]);
        });

    (new RunGame($game))->handle($engine);

    Queue::assertPushed(RunCurrentPhase::class, function (RunCurrentPhase $job) use ($game): bool {
        return $job->game->is($game)
            && $job->expectedRound === 1
            && $job->expectedPhase === Night::$name
            && $job->expectedPhaseStep === 0;
    });
});

test('RunCurrentPhase re-dispatches itself with the computed delay', function () {
    Queue::fake();

    $frozenNow = Carbon::create(2026, 2, 24, 12, 0, 0);
    Carbon::setTestNow($frozenNow);

    try {
        $game = Game::factory()->create([
            'status' => Running::getMorphClass(),
            'phase' => DayDiscussion::getMorphClass(),
            'round' => 2,
            'phase_step' => 4,
        ]);

        $engine = \Mockery::mock(GameEngine::class);
        $engine->shouldReceive('maxRounds')->twice()->andReturn(20);
        $engine->shouldReceive('runStep')
            ->once()
            ->with(\Mockery::on(fn (Game $phaseGame) => $phaseGame->is($game)))
            ->andReturnUsing(function (Game $phaseGame): array {
                $phaseGame->update(['phase_step' => 5]);

                return ['delay_seconds' => 7];
            });

        (new RunCurrentPhase($game, 2, DayDiscussion::$name, 4))->handle($engine);

        Queue::assertPushed(RunCurrentPhase::class, function (RunCurrentPhase $job) use ($game, $frozenNow): bool {
            return $job->game->is($game)
                && $job->expectedRound === 2
                && $job->expectedPhase === DayDiscussion::$name
                && $job->expectedPhaseStep === 5
                && $job->delay instanceof \DateTimeInterface
                && $job->delay->getTimestamp() === $frozenNow->copy()->addSeconds(7)->getTimestamp();
        });
    } finally {
        Carbon::setTestNow();
    }
});

test('RunCurrentPhase no-ops when job state is stale', function () {
    Queue::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 3,
        'phase_step' => 2,
    ]);

    $engine = \Mockery::mock(GameEngine::class);
    $engine->shouldReceive('maxRounds')->once()->andReturn(20);
    $engine->shouldNotReceive('runStep');

    (new RunCurrentPhase($game, 3, DayDiscussion::$name, 1))->handle($engine);

    Queue::assertNothingPushed();
});

test('RunCurrentPhase no-ops when game is already finished', function () {
    Queue::fake();

    $game = Game::factory()->create([
        'status' => Finished::getMorphClass(),
        'phase' => GameOver::getMorphClass(),
        'round' => 4,
        'phase_step' => 0,
    ]);

    $engine = \Mockery::mock(GameEngine::class);
    $engine->shouldNotReceive('maxRounds');
    $engine->shouldNotReceive('runStep');

    (new RunCurrentPhase($game, 4, GameOver::$name, 0))->handle($engine);

    Queue::assertNothingPushed();
});

test('RunCurrentPhase marks game as failed when the job fails', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayDiscussion::getMorphClass(),
        'round' => 3,
        'phase_step' => 1,
    ]);

    $job = new RunCurrentPhase($game, 3, DayDiscussion::$name, 1);
    $job->failed(new RuntimeException('Simulated job failure'));

    $game->refresh();

    expect($game->status)->toBeInstanceOf(Failed::class);

    $failureEvent = $game->events()
        ->where('type', 'game_failed')
        ->latest('id')
        ->first();

    expect($failureEvent)->not->toBeNull();
    expect($failureEvent->phase)->toBe(DayDiscussion::$name);
});
