<?php

use App\Ai\Agents\NightActionAgent;
use App\Enums\GameRole;
use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\NightBodyguard;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Event;

function createNightPlayer(Game $game, string $name, GameRole $role, int $order): Player
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

test('werewolf phase uses role hook and preserves event contract', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => NightWerewolf::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    createNightPlayer($game, 'Wolf', GameRole::Werewolf, 0);
    createNightPlayer($game, 'Villager', GameRole::Villager, 1);

    NightActionAgent::fake([
        'thinking' => 'target villager',
        'target_id' => 2,
        'public_reasoning' => 'high suspicion',
    ]);

    app(GameEngine::class)->runStep($game);

    $game->refresh();
    $event = $game->events()->where('type', 'werewolf_kill')->latest('id')->first();

    expect($event)->not->toBeNull();
    expect($event->data)->toHaveKeys(['thinking', 'public_reasoning']);
    expect($game->phase)->toBeInstanceOf(NightWerewolf::class);
    expect($game->phase_step)->toBe(1);
});

test('night pipeline transitions from werewolf to seer after proposals resolve', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => NightWerewolf::getMorphClass(),
        'round' => 2,
        'phase_step' => 1,
    ]);

    $wolf = createNightPlayer($game, 'Wolf', GameRole::Werewolf, 0);
    $villager = createNightPlayer($game, 'Villager', GameRole::Villager, 1);

    $game->events()->create([
        'round' => 2,
        'phase' => NightWerewolf::$name,
        'type' => 'werewolf_kill',
        'actor_player_id' => $wolf->id,
        'target_player_id' => $villager->id,
        'data' => ['thinking' => 'x', 'public_reasoning' => 'y'],
        'is_public' => false,
    ]);

    app(GameEngine::class)->runStep($game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(NightSeer::class);
});

test('seer and bodyguard hooks preserve event contracts', function () {
    Event::fake();

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => NightSeer::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    createNightPlayer($game, 'Seer', GameRole::Seer, 0);
    createNightPlayer($game, 'Bodyguard', GameRole::Bodyguard, 1);
    createNightPlayer($game, 'Villager', GameRole::Villager, 2);

    $nightCall = 0;
    NightActionAgent::fake(function () use (&$nightCall) {
        $nightCall++;

        if ($nightCall === 1) {
            return [
                'thinking' => 'investigate',
                'target_id' => 3,
                'public_reasoning' => 'suspicious',
            ];
        }

        return [
            'thinking' => 'protect',
            'target_id' => 3,
            'public_reasoning' => 'protect seer',
        ];
    });

    $engine = app(GameEngine::class);
    $engine->runStep($game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(NightBodyguard::class);

    $seerEvent = $game->events()->where('type', 'seer_investigate')->latest('id')->first();
    expect($seerEvent)->not->toBeNull();
    expect($seerEvent->data)->toHaveKeys(['thinking', 'public_reasoning', 'result']);

    $engine->runStep($game);
    $game->refresh();

    $bodyguardEvent = $game->events()->where('type', 'bodyguard_protect')->latest('id')->first();
    expect($bodyguardEvent)->not->toBeNull();
    expect($bodyguardEvent->data)->toHaveKeys(['thinking', 'public_reasoning']);
    expect($game->phase)->toBeInstanceOf(Dawn::class);
});
