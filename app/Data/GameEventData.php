<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class GameEventData extends Data
{
    public function __construct(
        public string $id,
        public int $round,
        public string $phase,
        public string $type,
        public ?string $actor_player_id,
        public ?string $target_player_id,
        public ?string $message,
        public ?string $thinking,
        public ?string $public_reasoning,
        public bool $is_public,
        public string $created_at,
        public ?string $audio_url = null,
        /** @var array<string, mixed>|null */
        public ?array $data = null,
    ) {}
}
