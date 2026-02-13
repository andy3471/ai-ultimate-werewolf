<?php

use App\Models\Game;
use App\Models\User;

test('guests are redirected to login for game index', function () {
    $this->get(route('games.index'))
        ->assertRedirect(route('login'));
});

test('guests are redirected to login for game create', function () {
    $this->get(route('games.create'))
        ->assertRedirect(route('login'));
});

test('guests are redirected to login for game store', function () {
    $this->post(route('games.store'))
        ->assertRedirect(route('login'));
});

test('guests are redirected to login for game show', function () {
    $game = Game::factory()->create();

    $this->get(route('games.show', $game))
        ->assertRedirect(route('login'));
});

test('guests are redirected to login for game start', function () {
    $game = Game::factory()->create();

    $this->post(route('games.start', $game))
        ->assertRedirect(route('login'));
});

test('guests are redirected to login for game state api', function () {
    $game = Game::factory()->create();

    $this->get(route('games.state', $game))
        ->assertRedirect(route('login'));
});

test('authenticated users can view the game index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('games.index'))
        ->assertOk();
});

test('authenticated users can view the game create page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('games.create'))
        ->assertOk();
});

test('authenticated users can create a game', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('games.store'), [
            'players' => [
                ['name' => 'Alice', 'provider' => 'openai', 'model' => 'gpt-4o', 'personality' => 'Aggressive'],
                ['name' => 'Bob', 'provider' => 'openai', 'model' => 'gpt-4o', 'personality' => 'Calm'],
                ['name' => 'Carol', 'provider' => 'openai', 'model' => 'gpt-4o', 'personality' => 'Nervous'],
                ['name' => 'Dave', 'provider' => 'openai', 'model' => 'gpt-4o', 'personality' => 'Charismatic'],
                ['name' => 'Eve', 'provider' => 'openai', 'model' => 'gpt-4o', 'personality' => 'Quiet'],
            ],
        ])
        ->assertRedirect();

    $game = Game::latest()->first();

    expect($game)->not->toBeNull()
        ->and($game->user_id)->toBe($user->id)
        ->and($game->players)->toHaveCount(5);
});

test('authenticated users can view a game', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create();

    $this->actingAs($user)
        ->get(route('games.show', $game))
        ->assertOk();
});

test('the game creator can start their game', function () {
    $user = User::factory()->create();
    $game = Game::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('games.start', $game))
        ->assertRedirect();
});

test('non-owners cannot start another users game', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $game = Game::factory()->for($owner)->create();

    $this->actingAs($other)
        ->post(route('games.start', $game))
        ->assertForbidden();
});

test('canStart is true for the game owner', function () {
    $user = User::factory()->create();
    $game = Game::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('games.show', $game))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('games/Show')
            ->where('canStart', true)
        );
});

test('canStart is false for non-owners', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $game = Game::factory()->for($owner)->create();

    $this->actingAs($other)
        ->get(route('games.show', $game))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('games/Show')
            ->where('canStart', false)
        );
});
