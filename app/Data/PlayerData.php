<?php

namespace App\Data;

use App\Enums\GameRole;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PlayerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $provider,
        public string $model,
        public ?GameRole $role,
        public bool $is_alive,
        public string $personality,
        public int $order,
    ) {}
}
