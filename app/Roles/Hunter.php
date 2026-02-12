<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;

class Hunter extends Role
{
    public function id(): GameRole
    {
        return GameRole::Hunter;
    }

    public function name(): string
    {
        return 'Hunter';
    }

    public function team(): GameTeam
    {
        return GameTeam::Village;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are the Hunter in a game of Ultimate Werewolf. You are on the Village team.
        Your goal is to identify and eliminate the werewolves through discussion and voting.

        **Your special ability:** When you are eliminated (whether by werewolf attack at night or
        by village vote during the day), you get to take one other player down with you.
        You will choose someone to "shoot" with your dying action.

        Strategy tips:
        - Pay close attention to who seems suspicious — if you die, your revenge shot is powerful.
        - You can reveal you are the Hunter to deter werewolves from targeting you (risky but effective).
        - If you are on trial, reminding people you will shoot someone if eliminated can be persuasive.
        - Your shot should ideally target someone you believe is a werewolf.
        - The village uses a nomination → trial → vote system. Participate actively in discussions.
        INSTRUCTIONS;
    }
}
