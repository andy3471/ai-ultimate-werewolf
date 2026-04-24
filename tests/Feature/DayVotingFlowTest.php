<?php

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\EliminationService;
use App\Services\GameEngine;
use App\Services\GameSteps\DayVotingStepRunner;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GameStatus\Running;

test('day voting returns to discussion when no player is nominated', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
        'phase_step' => 3,
    ]);

    for ($i = 0; $i < 3; $i++) {
        Player::create([
            'game_id' => $game->id,
            'name' => 'Player '.($i + 1),
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'role' => GameRole::Villager->value,
            'is_alive' => true,
            'personality' => 'neutral',
            'order' => $i,
        ]);
    }

    $dayActionService = Mockery::mock(DayActionService::class);
    $dayActionService->shouldReceive('createNominationResult')
        ->once()
        ->andReturn(null);

    $eliminationService = Mockery::mock(EliminationService::class);

    $runner = new DayVotingStepRunner($dayActionService, $eliminationService);

    $engine = Mockery::mock(GameEngine::class);
    $engine->shouldReceive('transitionToPhase')
        ->once()
        ->with(
            Mockery::on(fn (Game $runnerGame) => $runnerGame->is($game)),
            DayDiscussion::class,
        );

    $result = $runner->run($game, $engine);

    expect($result)->toBeTrue();
});

test('day action service records extension budget after failed vote reset', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
        'phase_step' => 50,
    ]);

    $players = collect();
    for ($i = 0; $i < 5; $i++) {
        $players->push(Player::create([
            'game_id' => $game->id,
            'name' => 'Player '.($i + 1),
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'role' => GameRole::Villager->value,
            'is_alive' => true,
            'personality' => 'neutral',
            'order' => $i,
        ]));
    }

    $dayActionService = app(DayActionService::class);
    $dayActionService->addDiscussionExtension($game, $players->count());

    $extensionEvent = $game->events()->where('type', 'discussion_extension')->latest('id')->first();
    expect($extensionEvent)->not->toBeNull();
    expect((int) ($extensionEvent->data['extra_budget'] ?? 0))->toBeGreaterThan(0);
});

test('nomination result ignores blocked nominee for same day cycle', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
        'phase_step' => 4,
    ]);

    $players = collect();
    for ($i = 0; $i < 3; $i++) {
        $players->push(Player::create([
            'game_id' => $game->id,
            'name' => 'Player '.($i + 1),
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'role' => GameRole::Villager->value,
            'is_alive' => true,
            'personality' => 'neutral',
            'order' => $i,
        ]));
    }

    $firstNominee = $players->get(0);
    $secondNominee = $players->get(1);
    $nominator = $players->get(2);

    $game->events()->create([
        'round' => $game->round,
        'phase' => $game->phase->getValue(),
        'type' => 'nomination',
        'actor_player_id' => $nominator->id,
        'target_player_id' => $firstNominee->id,
        'is_public' => true,
    ]);
    $game->events()->create([
        'round' => $game->round,
        'phase' => $game->phase->getValue(),
        'type' => 'nomination_block',
        'target_player_id' => $firstNominee->id,
        'is_public' => false,
    ]);
    $game->events()->create([
        'round' => $game->round,
        'phase' => $game->phase->getValue(),
        'type' => 'nomination',
        'actor_player_id' => $nominator->id,
        'target_player_id' => $secondNominee->id,
        'is_public' => true,
    ]);

    $result = app(DayActionService::class)->createNominationResult($game, $players);

    expect($result)->not->toBeNull();
    expect($result['accused']->id)->toBe($secondNominee->id);
});
