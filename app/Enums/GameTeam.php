<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum GameTeam: string
{
    case Village = 'village';
    case Werewolves = 'werewolves';
}
