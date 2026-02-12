<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class CreateGameData extends Data
{
    public function __construct(
        /** @var PlayerSlotData[] */
        #[DataCollectionOf(PlayerSlotData::class)]
        public array $players,
    ) {}
}
