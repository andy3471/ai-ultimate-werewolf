<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum GameRole: string
{
    case Werewolf = 'werewolf';
    case Villager = 'villager';
    case Seer = 'seer';
    case Bodyguard = 'bodyguard';
    case Hunter = 'hunter';
    case Tanner = 'tanner';
}
