<?php

namespace Database\Factories;

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'name' => fake()->unique()->firstName(),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'personality' => 'balanced',
            'role' => null,
            'is_alive' => true,
            'order' => 0,
        ];
    }

    public function role(GameRole $role): static
    {
        return $this->state(fn (): array => [
            'role' => $role,
        ]);
    }

    public function dead(): static
    {
        return $this->state(fn (): array => [
            'is_alive' => false,
        ]);
    }

    public function order(int $order): static
    {
        return $this->state(fn (): array => [
            'order' => $order,
        ]);
    }
}
