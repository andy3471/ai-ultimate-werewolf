<?php

use App\Contracts\Game\PhaseStepRunner;
use App\Http\Controllers\Controller;
use App\Services\GameSteps\DawnStepRunner;
use App\Services\GameSteps\DayDiscussionStepRunner;
use App\Services\GameSteps\DayVotingStepRunner;
use App\Services\GameSteps\DuskStepRunner;
use App\Services\GameSteps\NightRoleStepRunner;

arch('game step runners have runner suffix')
    ->expect('App\Services\GameSteps')
    ->toHaveSuffix('Runner');

arch('game step runners implement PhaseStepRunner')
    ->expect([
        NightRoleStepRunner::class,
        DawnStepRunner::class,
        DayDiscussionStepRunner::class,
        DayVotingStepRunner::class,
        DuskStepRunner::class,
    ])
    ->toImplement(PhaseStepRunner::class);

arch('roles do not use HTTP facades')
    ->expect('App\Roles')
    ->not->toUse([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Support\Facades\Request',
    ]);

arch('jobs are queueable')
    ->expect('App\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

arch('controllers stay in Http namespace')
    ->expect('App\Http\Controllers')
    ->toExtend(Controller::class);
