<?php

namespace App\Models;

use App\Data\PlayerData;
use App\Enums\GameRole;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'game_id',
        'name',
        'provider',
        'model',
        'personality',
        'role',
        'order',
        'is_alive',
        'voice',
    ];

    protected function casts(): array
    {
        return [
            'role' => GameRole::class,
            'is_alive' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function toData(bool $revealRole = false): PlayerData
    {
        return new PlayerData(
            id: $this->id,
            name: $this->name,
            provider: $this->provider,
            model: $this->model,
            role: $revealRole || ! $this->is_alive ? $this->role : null,
            is_alive: $this->is_alive,
            personality: $this->personality,
            order: $this->order,
        );
    }
}
