<?php

use App\Models\Game;

it('exposes an eight character reference from the game id', function () {
    $game = new Game;
    $game->id = '01a1a673-19f7-72ea-9f12-cb616bd9dd39';

    expect($game->reference())->toBe('01a1a673');
});
