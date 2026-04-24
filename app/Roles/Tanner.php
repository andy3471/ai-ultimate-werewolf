<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Models\Game;
use App\Models\Player;

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

    public function rulesPrompt(): string
    {
        return 'Tanner (Neutral team): Wins only if eliminated by village vote during the day. If killed at night or still alive when another team wins, Tanner loses.';
    }

    public function villageEliminationWinOutcome(Game $game, Player $eliminated): ?array
    {
        return [
            'team' => GameTeam::Neutral,
            'message' => "{$eliminated->name} was the Tanner and WANTED to be eliminated! The Tanner wins!",
            'broadcast_message' => "{$eliminated->name} was the Tanner and wins!",
        ];
    }

    public function gameOverVoiceWinnerAddendum(GameTeam $winner): ?string
    {
        return $winner === GameTeam::Neutral
            ? 'The Tanner wins — they tricked the village into eliminating them!'
            : null;
    }

    public function standardDeckCopies(int $playerCount): int
    {
        return $playerCount >= 7 ? 1 : 0;
    }

    public function standardDeckCompositionOrder(): int
    {
        return 50;
    }
}
