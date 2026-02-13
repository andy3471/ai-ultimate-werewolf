<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\User;

class GamePolicy
{
    /**
     * Any authenticated user can view the game list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user can view a game.
     */
    public function view(User $user, Game $game): bool
    {
        return true;
    }

    /**
     * Any authenticated user can create a game.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Only the game creator can start the game.
     */
    public function start(User $user, Game $game): bool
    {
        return $user->id === $game->user_id;
    }
}
