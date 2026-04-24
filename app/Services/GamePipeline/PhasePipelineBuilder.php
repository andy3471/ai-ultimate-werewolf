<?php

namespace App\Services\GamePipeline;

use App\Models\Game;
use App\Services\GameEngine;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Dusk;
use App\States\GamePhase\NightBodyguard;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;

class PhasePipelineBuilder
{
    public function build(Game $game, GameEngine $engine): PhasePipeline
    {
        return new PhasePipeline([
            new EngineDelegatingPhaseHandler(NightWerewolf::class, $engine, 'runNightWerewolfStep'),
            new EngineDelegatingPhaseHandler(NightSeer::class, $engine, 'runNightSeerStep'),
            new EngineDelegatingPhaseHandler(NightBodyguard::class, $engine, 'runNightBodyguardStep'),
            new EngineDelegatingPhaseHandler(Dawn::class, $engine, 'runDawnStep'),
            new EngineDelegatingPhaseHandler(DayDiscussion::class, $engine, 'runDayDiscussionStep'),
            new EngineDelegatingPhaseHandler(DayVoting::class, $engine, 'runDayVotingStep'),
            new EngineDelegatingPhaseHandler(Dusk::class, $engine, 'runDuskStep'),
        ]);
    }
}
