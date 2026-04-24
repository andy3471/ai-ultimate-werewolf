<?php

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\GameEngine;
use App\Services\WinConditionResolver;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\GameOver;
use App\States\GameStatus\Finished;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Event;

function createVotingPlayer(Game $game, string $name, GameRole $role, int $order): Player
{
    return Player::create([
        'game_id' => $game->id,
        'name' => $name,
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => $role->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => $order,
    ]);
}

test('trial outcome emits stable vote events contract', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    $accused = createVotingPlayer($game, 'Accused', GameRole::Villager, 0);
    $voterYes = createVotingPlayer($game, 'Voter Yes', GameRole::Villager, 1);
    $voterNo = createVotingPlayer($game, 'Voter No', GameRole::Seer, 2);

    $game->events()->create([
        'round' => $game->round,
        'phase' => $game->phase->getValue(),
        'type' => 'vote',
        'actor_player_id' => $voterYes->id,
        'target_player_id' => $accused->id,
        'data' => ['vote' => 'yes'],
        'is_public' => true,
    ]);

    $game->events()->create([
        'round' => $game->round,
        'phase' => $game->phase->getValue(),
        'type' => 'vote',
        'actor_player_id' => $voterNo->id,
        'target_player_id' => null,
        'data' => ['vote' => 'no'],
        'is_public' => true,
    ]);

    app(DayActionService::class)->createTrialOutcome($game, $accused, app(GameEngine::class));

    $tally = $game->events()->where('type', 'vote_tally')->latest('id')->first();
    $outcome = $game->events()->where('type', 'vote_outcome')->latest('id')->first();

    expect($tally)->not->toBeNull();
    expect($tally->data)->toHaveKeys(['message', 'yes', 'no']);
    expect($outcome)->not->toBeNull();
    expect($outcome->data)->toHaveKeys(['eliminated_id']);
});

test('dawn resolution events keep stable contract keys', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Dawn::getMorphClass(),
        'round' => 2,
    ]);

    $target = createVotingPlayer($game, 'Dead', GameRole::Villager, 0);

    $death = $game->events()->create([
        'round' => 2,
        'phase' => 'dawn',
        'type' => 'death',
        'target_player_id' => $target->id,
        'data' => [
            'message' => 'Test died.',
            'role_revealed' => 'villager',
        ],
        'is_public' => true,
    ]);

    $saveTarget = createVotingPlayer($game, 'Saved', GameRole::Villager, 1);

    $save = $game->events()->create([
        'round' => 2,
        'phase' => 'dawn',
        'type' => 'bodyguard_save',
        'target_player_id' => $saveTarget->id,
        'data' => ['message' => 'Saved.'],
        'is_public' => true,
    ]);

    $peace = $game->events()->create([
        'round' => 2,
        'phase' => 'dawn',
        'type' => 'no_death',
        'data' => ['message' => 'Peaceful.'],
        'is_public' => true,
    ]);

    expect($death->data)->toHaveKeys(['message', 'role_revealed']);
    expect($save->data)->toHaveKey('message');
    expect($peace->data)->toHaveKey('message');
});

test('game_end event keeps winner and message contract', function () {
    $game = Game::factory()->create([
        'status' => Finished::getMorphClass(),
        'phase' => GameOver::getMorphClass(),
        'round' => 3,
    ]);

    $game->events()->create([
        'round' => 3,
        'phase' => 'game_over',
        'type' => 'game_end',
        'data' => [
            'winner' => 'village',
            'message' => 'Village wins.',
        ],
        'is_public' => true,
    ]);

    $end = $game->events()->where('type', 'game_end')->latest('id')->first();
    expect($end)->not->toBeNull();
    expect($end->data)->toHaveKeys(['winner', 'message']);
});

test('tanner village elimination finalizes neutral win', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
    ]);

    $tanner = Player::create([
        'game_id' => $game->id,
        'name' => 'Tanner',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Tanner->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 0,
    ]);

    Event::fake();

    $resolved = app(WinConditionResolver::class)->resolve($game, $tanner);

    expect($resolved)->toBeTrue();
    $game->refresh();
    expect($game->winner?->value)->toBe('neutral');

    $end = $game->events()->where('type', 'game_end')->latest('id')->first();
    expect($end)->not->toBeNull();
    expect($end->data['winner'])->toBe('neutral');
});
