<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PlayerSlotData extends Data
{
    public function __construct(
        public string $name,
        public string $provider,
        public string $model,
        public string $personality,
    ) {}
}
