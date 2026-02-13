<?php

namespace App\Models;

use App\Data\GameData;
use App\Enums\GameTeam;
use App\States\GamePhase\GamePhaseState;
use App\States\GameStatus\GameStatusState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\ModelStates\HasStates;

class Game extends Model
{
    /** @use HasFactory<\Database\Factories\GameFactory> */
    use HasFactory, HasStates, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GameStatusState::class,
            'phase' => GamePhaseState::class,
            'winner' => GameTeam::class,
            'role_distribution' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class)->orderBy('order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class)->orderBy('id');
    }

    public function alivePlayers(): HasMany
    {
        return $this->hasMany(Player::class)->where('is_alive', true)->orderBy('order');
    }

    public function toData(): GameData
    {
        return new GameData(
            id: $this->id,
            userId: $this->user_id,
            status: $this->status->getValue(),
            phase: $this->phase->getValue(),
            round: $this->round,
            winner: $this->winner,
            role_distribution: $this->role_distribution,
            players: $this->players->map(fn (Player $player) => $player->toData(revealRole: true))->all(),
            events: $this->events->map(fn (GameEvent $event) => $event->toData())->all(),
            created_at: $this->created_at->toISOString(),
        );
    }
}
