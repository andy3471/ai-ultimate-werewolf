<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class GameEventData extends Data
{
    public function __construct(
        public int $id,
        public int $round,
        public string $phase,
        public string $type,
        public ?int $actor_player_id,
        public ?int $target_player_id,
        public ?string $message,
        public ?string $thinking,
        public ?string $public_reasoning,
        public bool $is_public,
        public string $created_at,
        /** @var array<string, mixed>|null */
        public ?array $data = null,
    ) {}
}
