<?php

use App\Jobs\RunCurrentPhase;
use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\DayDiscussion;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Queue;

test('stale replayed step no-ops', function () {
    Queue::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayDiscussion::getMorphClass(),
        'round' => 2,
        'phase_step' => 3,
    ]);

    $engine = \Mockery::mock(GameEngine::class);
    $engine->shouldReceive('maxRounds')->once()->andReturn(20);
    $engine->shouldNotReceive('runStep');

    (new RunCurrentPhase($game, 2, DayDiscussion::$name, 2))->handle($engine);

    Queue::assertNothingPushed();
});

test('out-of-order future step no-ops', function () {
    Queue::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayDiscussion::getMorphClass(),
        'round' => 2,
        'phase_step' => 1,
    ]);

    $engine = \Mockery::mock(GameEngine::class);
    $engine->shouldReceive('maxRounds')->once()->andReturn(20);
    $engine->shouldNotReceive('runStep');

    (new RunCurrentPhase($game, 2, DayDiscussion::$name, 3))->handle($engine);

    Queue::assertNothingPushed();
});

test('same step cannot double-apply when token advances', function () {
    Queue::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayDiscussion::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    $engine = \Mockery::mock(GameEngine::class);
    $engine->shouldReceive('maxRounds')->times(3)->andReturn(20);
    $engine->shouldReceive('runStep')
        ->once()
        ->with(\Mockery::on(fn (Game $phaseGame) => $phaseGame->is($game)))
        ->andReturnUsing(function () use ($game): array {
            $game->update(['phase_step' => 1]);

            return ['delay_seconds' => 2];
        });

    (new RunCurrentPhase($game, 2, DayDiscussion::$name, 0))->handle($engine);
    (new RunCurrentPhase($game, 2, DayDiscussion::$name, 0))->handle($engine);

    Queue::assertPushed(RunCurrentPhase::class, 1);
});
