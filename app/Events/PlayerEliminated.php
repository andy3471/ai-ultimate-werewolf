<?php

namespace App\Events;

use App\Data\GameEventData;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerEliminated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $gameId,
        public GameEventData $event,
        public int $playerId,
        public string $role,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("game.{$this->gameId}");
    }

    public function broadcastAs(): string
    {
        return 'player.eliminated';
    }
}
