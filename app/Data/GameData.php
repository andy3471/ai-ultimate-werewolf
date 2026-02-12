<?php

namespace App\Data;

use App\Enums\GameTeam;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class GameData extends Data
{
    public function __construct(
        public int $id,
        public string $status,
        public string $phase,
        public int $round,
        public ?GameTeam $winner,
        /** @var array<string, int>|null */
        public ?array $role_distribution,
        /** @var PlayerData[] */
        public array $players,
        /** @var GameEventData[] */
        public array $events,
        public string $created_at,
    ) {}
}
