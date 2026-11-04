<?php

use App\Models\Game;
use App\Models\User;

it('includes a short game reference in game payloads', function () {
    $user = User::factory()->create();
    $game = Game::factory()->for($user)->create([
        'id' => '01a1a673-19f7-72ea-9f12-cb616bd9dd39',
    ]);

    $this->actingAs($user)
        ->get(route('games.show', $game))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('games/Show')
            ->where('game.id', '01a1a673-19f7-72ea-9f12-cb616bd9dd39')
            ->where('game.reference', '01a1a673')
        );
});
