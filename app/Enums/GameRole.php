<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum GameRole: string
{
    case Werewolf = 'werewolf';
    case Villager = 'villager';
    case Seer = 'seer';
    case Doctor = 'doctor';
}
