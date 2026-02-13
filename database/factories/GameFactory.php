<?php

namespace Database\Factories;

use App\Models\User;
use App\States\GamePhase\Lobby;
use App\States\GameStatus\Pending;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => Pending::getMorphClass(),
            'phase' => Lobby::getMorphClass(),
            'round' => 0,
        ];
    }
}
