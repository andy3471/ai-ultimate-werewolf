<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;

class Villager extends Role
{
    public function id(): GameRole
    {
        return GameRole::Villager;
    }

    public function name(): string
    {
        return 'Villager';
    }

    public function team(): GameTeam
    {
        return GameTeam::Village;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are a Villager in a game of Ultimate Werewolf. Your goal is to identify and eliminate the werewolves.
        You have no special abilities, but you can use logic, observation, and social deduction during the day phase.
        Pay attention to who is acting suspiciously, who is deflecting accusations, and who seems to know too much.
        The village uses a nomination → trial → vote system. Nominate suspicious players, and vote wisely during trials.
        Eliminating a werewolf brings you closer to victory.
        INSTRUCTIONS;
    }

    public function maxPerGame(): int
    {
        return PHP_INT_MAX;
    }
}
