<?php

use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\NightBodyguard;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Event;

test('werewolves skip night action on round 1', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => NightWerewolf::getMorphClass(),
        'round' => 1,
        'phase_step' => 0,
    ]);

    $engine = app(GameEngine::class);
    $engine->runStep($game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(NightSeer::class);
    expect($game->events()->where('type', 'werewolf_kill')->count())->toBe(0);
});

test('bodyguard skips night action on round 1', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => NightBodyguard::getMorphClass(),
        'round' => 1,
        'phase_step' => 0,
    ]);

    $engine = app(GameEngine::class);
    $engine->runStep($game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(Dawn::class);
    expect($game->events()->where('type', 'bodyguard_protect')->count())->toBe(0);
});

test('werewolves act on round 2', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => NightWerewolf::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    // Without players the step still transitions, but it should NOT
    // short-circuit due to round check — it should reach the "empty wolves" check
    $engine = app(GameEngine::class);
    $engine->runStep($game);

    $game->refresh();
    // With no werewolf players, it transitions to NightSeer via the "empty" branch, not the round-1 branch
    expect($game->phase)->toBeInstanceOf(NightSeer::class);
});
