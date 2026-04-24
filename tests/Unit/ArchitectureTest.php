<?php

arch('game step runners have runner suffix')
    ->expect('App\Services\GameSteps')
    ->toHaveSuffix('Runner');
