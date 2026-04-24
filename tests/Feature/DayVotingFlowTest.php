<?php

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\EliminationService;
use App\Services\GameEngine;
use App\Services\GameSteps\DayVotingStepRunner;
use App\Services\RoleRegistry;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Dusk;
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
    $dayActionService->shouldReceive('createNominationResult')->never();
    $dayActionService->shouldReceive('createNomination')->never();

    $eliminationService = Mockery::mock(EliminationService::class);

    $runner = new DayVotingStepRunner($dayActionService, $eliminationService, app(RoleRegistry::class));

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

test('seconding window opens after first nomination result instead of waiting for full nomination round', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
        'phase_step' => 1,
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

    $game->events()->create([
        'round' => $game->round,
        'phase' => $game->phase->getValue(),
        'type' => 'nomination',
        'actor_player_id' => $players->first()->id,
        'target_player_id' => $players->get(1)->id,
        'is_public' => true,
    ]);

    $runner = new DayVotingStepRunner(app(DayActionService::class), Mockery::mock(EliminationService::class), app(RoleRegistry::class));
    $engine = Mockery::mock(GameEngine::class);
    $engine->shouldReceive('transitionToPhase')->never();

    $result = $runner->run($game, $engine);

    expect($result)->toBeFalse();
    $nominationResult = $game->events()->where('type', 'nomination_result')->latest('id')->first();
    expect($nominationResult)->not->toBeNull();
    expect((string) ($nominationResult->data['nominator_id'] ?? ''))->toBe($players->first()->id);
});

test('game phase allows transition from day voting back to day discussion', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 1,
        'phase_step' => 0,
    ]);

    $game->phase->transitionTo(DayDiscussion::class);
    $game->refresh();

    expect($game->phase)->toBeInstanceOf(DayDiscussion::class);
});

test('successful trial elimination after hunter follow-up transitions to dusk not day discussion', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 1,
        'phase_step' => 200,
    ]);

    $hunter = Player::create([
        'game_id' => $game->id,
        'name' => 'Hunter',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Hunter->value,
        'is_alive' => false,
        'personality' => 'neutral',
        'order' => 0,
    ]);
    $bodyguard = Player::create([
        'game_id' => $game->id,
        'name' => 'Bodyguard',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Bodyguard->value,
        'is_alive' => false,
        'personality' => 'neutral',
        'order' => 1,
    ]);
    $seer = Player::create([
        'game_id' => $game->id,
        'name' => 'Seer',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Seer->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 2,
    ]);
    $villager = Player::create([
        'game_id' => $game->id,
        'name' => 'Villager',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Villager->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 3,
    ]);
    $wolf = Player::create([
        'game_id' => $game->id,
        'name' => 'Wolf',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Werewolf->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 4,
    ]);

    $phase = $game->phase->getValue();

    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'nomination_result',
        'target_player_id' => $hunter->id,
        'data' => [
            'nominator_id' => $seer->id,
            'nomination_result_step' => 10,
        ],
        'is_public' => true,
    ]);
    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'nomination_second',
        'actor_player_id' => $villager->id,
        'target_player_id' => $hunter->id,
        'is_public' => true,
    ]);
    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'defense_speech',
        'actor_player_id' => $hunter->id,
        'is_public' => true,
    ]);
    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'defense_speech',
        'actor_player_id' => $seer->id,
        'is_public' => true,
    ]);

    foreach ([$seer, $bodyguard, $villager, $wolf, $hunter] as $voter) {
        $game->events()->create([
            'round' => 1,
            'phase' => $phase,
            'type' => 'vote',
            'actor_player_id' => $voter->id,
            'target_player_id' => $hunter->id,
            'data' => ['vote' => 'yes'],
            'is_public' => true,
        ]);
    }

    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'vote_outcome',
        'data' => ['eliminated_id' => $hunter->id],
        'is_public' => false,
    ]);
    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'dying_speech',
        'actor_player_id' => $hunter->id,
        'is_public' => true,
    ]);
    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'hunter_shot',
        'actor_player_id' => $hunter->id,
        'target_player_id' => $bodyguard->id,
        'data' => ['message' => 'shot'],
        'is_public' => true,
    ]);
    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'dying_speech',
        'actor_player_id' => $bodyguard->id,
        'is_public' => true,
    ]);
    $game->events()->create([
        'round' => 1,
        'phase' => $phase,
        'type' => 'hunter_shot_followup_done',
        'is_public' => false,
    ]);

    $runner = new DayVotingStepRunner(app(DayActionService::class), app(EliminationService::class), app(RoleRegistry::class));
    $engine = Mockery::mock(GameEngine::class);
    $engine->shouldReceive('checkWinCondition')->andReturn(false);
    $engine->shouldReceive('transitionToPhase')
        ->once()
        ->with(Mockery::on(fn (Game $g) => $g->is($game)), Dusk::class);

    $runner->run($game, $engine);
});
