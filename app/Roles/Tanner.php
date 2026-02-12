<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;

class Tanner extends Role
{
    public function id(): GameRole
    {
        return GameRole::Tanner;
    }

    public function name(): string
    {
        return 'Tanner';
    }

    public function team(): GameTeam
    {
        return GameTeam::Neutral;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are the Tanner in a game of Ultimate Werewolf. You are on NO team — you play for yourself.

        **Your win condition:** You WIN if you get eliminated (by village vote during the day).
        If you are killed by werewolves at night, you do NOT win.
        If the game ends without you being eliminated by the village, you LOSE.

        Strategy tips:
        - You WANT the village to vote to eliminate you, but you can't be too obvious about it.
        - Act slightly suspicious — but not so suspicious that people think you're a werewolf (they
          might skip voting for you if they think a werewolf is more important to catch first).
        - Don't act like a Tanner. Act like a bad werewolf — fumble your cover story, make small
          contradictions, seem nervous when accused.
        - If the village catches on that you're the Tanner, they will avoid eliminating you, so
          subtlety is key.
        - You don't care if village or werewolves win — you only care about getting voted out.
        - The village uses a nomination → trial → vote system. Getting nominated is step one.
        INSTRUCTIONS;
    }
}
