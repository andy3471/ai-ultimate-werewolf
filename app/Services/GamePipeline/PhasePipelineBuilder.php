<?php

namespace App\Services\GamePipeline;

use App\Models\Game;
use App\Services\GameEngine;
use App\Services\GameSteps\DawnStepRunner;
use App\Services\GameSteps\DayDiscussionStepRunner;
use App\Services\GameSteps\DayVotingStepRunner;
use App\Services\GameSteps\DuskStepRunner;
use App\Services\GameSteps\NightRoleStepRunner;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Dusk;
use App\States\GamePhase\Night;

class PhasePipelineBuilder
{
    public function __construct(
        protected NightRoleStepRunner $nightRoleStepRunner,
        protected DawnStepRunner $dawnStepRunner,
        protected DayDiscussionStepRunner $dayDiscussionStepRunner,
        protected DayVotingStepRunner $dayVotingStepRunner,
        protected DuskStepRunner $duskStepRunner,
    ) {}

    public function build(Game $game, GameEngine $engine): PhasePipeline
    {
        return new PhasePipeline([
            new RunnerDelegatingPhaseHandler(Night::class, $this->nightRoleStepRunner, $engine),
            new RunnerDelegatingPhaseHandler(Dawn::class, $this->dawnStepRunner, $engine),
            new RunnerDelegatingPhaseHandler(DayDiscussion::class, $this->dayDiscussionStepRunner, $engine),
            new RunnerDelegatingPhaseHandler(DayVoting::class, $this->dayVotingStepRunner, $engine),
            new RunnerDelegatingPhaseHandler(Dusk::class, $this->duskStepRunner, $engine),
        ]);
    }
}
