<?php

namespace App\Roles;

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Models\Game;
use App\Models\Player;
use App\States\GamePhase\NightSeer;

class Seer extends Role
{
    public function id(): GameRole
    {
        return GameRole::Seer;
    }

    public function name(): string
    {
        return 'Seer';
    }

    public function team(): GameTeam
    {
        return GameTeam::Village;
    }

    public function nightPhase(): string
    {
        return NightSeer::class;
    }

    public function nightActionPrompt(): string
    {
        return <<<'PROMPT'
        You are the Seer. It is night time and you may investigate one player to learn their true allegiance.
        Choose a player you are suspicious of. You will learn whether they are a Werewolf or a Villager (aligned with the village).
        Use this information wisely during the day — but be careful about revealing yourself, or the werewolves will target you.
        PROMPT;
    }

    public function describeNightResult(Player $actor, Player $target, Game $game): ?string
    {
        $targetRole = app(\App\Services\RoleRegistry::class)->get($target->role);

        return $target->name.' is aligned with the '.($targetRole->team() === GameTeam::Werewolves ? 'Werewolves' : 'Village').'.';
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are the Seer in a game of Ultimate Werewolf. Your goal is to identify the werewolves and help the village eliminate them.
        Each night, you can investigate one player to learn their true allegiance (Werewolf or Village).
        During the day, you must decide how to use this information. You can share it openly, hint at it subtly,
        or keep it secret to avoid being targeted by the werewolves. Balance information sharing with self-preservation.
        The village uses a nomination → trial → vote system. Help guide nominations toward confirmed werewolves.
        INSTRUCTIONS;
    }
}
