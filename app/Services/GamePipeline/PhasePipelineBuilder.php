<?php

namespace App\Services\GamePipeline;

use App\Enums\GameRole;
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
use App\States\GamePhase\NightBodyguard;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;

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
            new NightRolePhaseHandler(NightWerewolf::class, GameRole::Werewolf, NightSeer::class, $this->nightRoleStepRunner, $engine),
            new NightRolePhaseHandler(NightSeer::class, GameRole::Seer, NightBodyguard::class, $this->nightRoleStepRunner, $engine),
            new NightRolePhaseHandler(NightBodyguard::class, GameRole::Bodyguard, Dawn::class, $this->nightRoleStepRunner, $engine, narrateNextPhase: false),
            new RunnerDelegatingPhaseHandler(Dawn::class, $this->dawnStepRunner, $engine),
            new RunnerDelegatingPhaseHandler(DayDiscussion::class, $this->dayDiscussionStepRunner, $engine),
            new RunnerDelegatingPhaseHandler(DayVoting::class, $this->dayVotingStepRunner, $engine),
            new RunnerDelegatingPhaseHandler(Dusk::class, $this->duskStepRunner, $engine),
        ]);
    }
}
