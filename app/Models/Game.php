<?php

namespace App\Models;

use App\Data\GameData;
use App\Data\GameEventData;
use App\Data\PlayerData;
use App\Enums\GameTeam;
use App\States\GamePhase\GamePhaseState;
use App\States\GameStatus\GameStatusState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\ModelStates\HasStates;

class Game extends Model
{
    use HasStates;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GameStatusState::class,
            'phase' => GamePhaseState::class,
            'winner' => GameTeam::class,
        ];
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
            status: $this->status->getValue(),
            phase: $this->phase->getValue(),
            round: $this->round,
            winner: $this->winner,
            players: $this->players->map(fn (Player $player) => $player->toData())->all(),
            events: $this->events->map(fn (GameEvent $event) => $event->toData())->all(),
            created_at: $this->created_at->toISOString(),
        );
    }
}
