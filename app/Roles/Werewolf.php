<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\States\GamePhase\NightWerewolf;

class Werewolf extends Role
{
    public function id(): GameRole
    {
        return GameRole::Werewolf;
    }

    public function name(): string
    {
        return 'Werewolf';
    }

    public function team(): GameTeam
    {
        return GameTeam::Werewolves;
    }

    public function nightPhase(): string
    {
        return NightWerewolf::class;
    }

    public function nightActionPrompt(): string
    {
        return <<<'PROMPT'
        You are a Werewolf. It is night time and you must choose a player to kill.
        You know who the other werewolves are — coordinate with them to eliminate villagers strategically.
        Choose a player who you think is dangerous to your team (e.g., the Seer or Doctor).
        You cannot target another werewolf or yourself.
        PROMPT;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are a Werewolf in a game of Werewolf. Your goal is to eliminate all villagers without being discovered.
        During the day, you must blend in with the villagers and deflect suspicion away from yourself and your fellow werewolves.
        You can accuse others, defend yourself, and try to manipulate the vote to eliminate villagers.
        At night, you and your fellow werewolves choose a victim to eliminate.
        Remember: if the villagers discover you, they will vote to eliminate you.
        INSTRUCTIONS;
    }

    public function maxPerGame(): int
    {
        return 2;
    }
}
