<?php

use App\Ai\Agents\VoteAgent;
use App\Enums\GameRole;
use App\Events\PlayerActed;
use App\Models\Game;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\GameEngine;
use App\States\GamePhase\DayVoting;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Event;

it('records a trial vote through VoteAgent fake responses', function () {
    Event::fake([PlayerActed::class]);

    VoteAgent::fake([
        'thinking' => 'They seem guilty.',
        'public_reasoning' => 'Suspicious answers.',
        'target_id' => 1,
    ]);

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    $accused = Player::factory()->for($game)->role(GameRole::Villager)->order(0)->create(['name' => 'Accused']);
    $voter = Player::factory()->for($game)->role(GameRole::Seer)->order(1)->create(['name' => 'Voter']);

    $engine = app(GameEngine::class);

    app(DayActionService::class)->createTrialVote($game, $voter, $accused, $engine);

    $vote = $game->events()
        ->where('type', 'vote')
        ->where('actor_player_id', $voter->id)
        ->first();

    expect($vote)->not->toBeNull()
        ->and($vote->data)->toHaveKey('thinking');

    Event::assertDispatched(PlayerActed::class);
});
