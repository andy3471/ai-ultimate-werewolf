<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Game channels are public (anyone can watch a game)
Broadcast::channel('game.{gameId}', function () {
    return true;
});
