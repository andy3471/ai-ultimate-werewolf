<?php

namespace App\Events;

use App\Data\GameData;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GamePhaseChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $gameId,
        public string $phase,
        public int $round,
        public string $description,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("game.{$this->gameId}");
    }

    public function broadcastAs(): string
    {
        return 'phase.changed';
    }
}
