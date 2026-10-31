<?php

use App\Jobs\RunGame;
use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('loads public pages without javascript errors', function () {
    $this->visit(['/', '/login', '/games'])
        ->assertNoJavaScriptErrors();
});

it('lets an authenticated owner create a game and open the lobby', function () {
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

    $game = Game::query()->latest('id')->first();
    expect($game)->not->toBeNull()
        ->and($game->players)->toHaveCount(5);

    $this->actingAs($user)
        ->visit(route('games.show', $game))
        ->assertSee('Start Game')
        ->assertNoJavaScriptErrors();
});

it('lets the owner start a pending game from the lobby', function () {
    Queue::fake();

    $user = User::factory()->create();
    $game = Game::factory()->for($user)->create();
    Player::factory()->for($game)->count(5)->create();

    $this->actingAs($user)
        ->post(route('games.start', $game))
        ->assertRedirect();

    Queue::assertPushed(RunGame::class);

    $this->actingAs($user)
        ->visit(route('games.show', $game))
        ->assertNoJavaScriptErrors();
});
