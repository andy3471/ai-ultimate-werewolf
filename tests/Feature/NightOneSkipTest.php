<?php

use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\Night;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Event;

test('night step skips werewolf and bodyguard actions on round 1', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Night::getMorphClass(),
        'round' => 1,
        'phase_step' => 0,
    ]);

    $engine = app(GameEngine::class);
    $engine->runStep($game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(Dawn::class);
    expect($game->events()->where('type', 'werewolf_kill')->count())->toBe(0);
    expect($game->events()->where('type', 'bodyguard_protect')->count())->toBe(0);
});

test('night transitions to dawn after last configured night slot', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Night::getMorphClass(),
        'round' => 1,
        'phase_step' => 1,
    ]);

    $engine = app(GameEngine::class);
    $engine->runStep($game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(Dawn::class);
    expect($game->events()->where('type', 'bodyguard_protect')->count())->toBe(0);
});

test('night skips missing role actions on round 2', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Night::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    $engine = app(GameEngine::class);
    $engine->runStep($game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(Dawn::class);
});
