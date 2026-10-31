<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CreateGameData extends Data
{
    public function __construct(
        /** @var list<PlayerSlotData> */
        #[DataCollectionOf(PlayerSlotData::class)]
        public array $players,
    ) {}
}
