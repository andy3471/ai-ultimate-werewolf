<?php

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\GameEngine;
use App\States\GamePhase\DayVoting;
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
