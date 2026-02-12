<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\States\GamePhase\NightDoctor;

class Doctor extends Role
{
    public function id(): GameRole
    {
        return GameRole::Doctor;
    }

    public function name(): string
    {
        return 'Doctor';
    }

    public function team(): GameTeam
    {
        return GameTeam::Village;
    }

    public function nightPhase(): string
    {
        return NightDoctor::class;
    }

    public function nightActionPrompt(): string
    {
        return <<<'PROMPT'
        You are the Doctor. It is night time and you may protect one player from being killed by the werewolves.
        Choose a player you think the werewolves might target. If the werewolves target the player you protect, they will survive.
        You may also choose to protect yourself.
        PROMPT;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are the Doctor in a game of Werewolf. Your goal is to protect villagers from werewolf attacks.
        Each night, you can choose one player to protect. If the werewolves target that player, they will survive.
        During the day, be careful about revealing your role — if the werewolves know who you are, they may try to eliminate you.
        Use your protection wisely: protect players who seem valuable to the village (like a suspected Seer).
        INSTRUCTIONS;
    }
}
