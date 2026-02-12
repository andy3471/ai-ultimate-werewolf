<?php

namespace App\Models;

use App\Data\GameEventData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'actor_player_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'target_player_id');
    }

    public function toData(): GameEventData
    {
        // Pass through safe extra fields (vote direction, tally, etc.)
        $extraData = collect($this->data ?? [])
            ->except(['thinking', 'public_reasoning', 'message'])
            ->all();

        return new GameEventData(
            id: $this->id,
            round: $this->round,
            phase: $this->phase,
            type: $this->type,
            actor_player_id: $this->actor_player_id,
            target_player_id: $this->target_player_id,
            message: $this->data['message'] ?? null,
            thinking: $this->data['thinking'] ?? null,
            public_reasoning: $this->data['public_reasoning'] ?? null,
            is_public: $this->is_public,
            created_at: $this->created_at->toISOString(),
            data: ! empty($extraData) ? $extraData : null,
        );
    }
}
