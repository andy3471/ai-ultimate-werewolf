<?php

use App\Enums\GameRole;
use App\Events\GamePhaseChanged;
use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\Services\NightResolver;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\Night;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Event;

it('resolves a bodyguard save through NightResolver into dawn events', function () {
    Event::fake([GamePhaseChanged::class]);

    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Night::getMorphClass(),
        'round' => 2,
    ]);

    $victim = Player::factory()->for($game)->role(GameRole::Villager)->order(0)->create();
    Player::factory()->for($game)->role(GameRole::Werewolf)->order(1)->create();
    $bodyguard = Player::factory()->for($game)->role(GameRole::Bodyguard)->order(2)->create();

    $game->events()->create([
        'round' => 2,
        'phase' => 'night',
        'type' => 'werewolf_kill',
        'target_player_id' => $victim->id,
        'is_public' => false,
    ]);

    $game->events()->create([
        'round' => 2,
        'phase' => 'night',
        'type' => 'bodyguard_protect',
        'actor_player_id' => $bodyguard->id,
        'target_player_id' => $victim->id,
        'is_public' => false,
    ]);

    $result = app(NightResolver::class)->resolve($game);

    expect($result['killed'])->toBeNull()
        ->and($result['events'])->not->toBeEmpty();

    $save = $game->events()
        ->where('round', 2)
        ->where('type', 'bodyguard_save')
        ->first();

    expect($save)->not->toBeNull()
        ->and($save->data)->toHaveKey('message');
});

it('creates dawn resolution via GameEngine and keeps death contract keys', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Dawn::getMorphClass(),
        'round' => 2,
        'phase_step' => 0,
    ]);

    $victim = Player::factory()->for($game)->role(GameRole::Villager)->order(0)->create();
    Player::factory()->for($game)->role(GameRole::Werewolf)->order(1)->create();

    $game->events()->create([
        'round' => 2,
        'phase' => 'night',
        'type' => 'werewolf_kill',
        'target_player_id' => $victim->id,
        'is_public' => false,
    ]);

    $game->update(['phase' => Dawn::getMorphClass()]);

    $resolution = app(GameEngine::class)->getOrCreateDawnResolution($game);

    expect($resolution['killed_player_id'])->toBe($victim->id);

    $death = $game->events()
        ->where('round', 2)
        ->where('type', 'death')
        ->first();

    expect($death)->not->toBeNull()
        ->and($death->data)->toHaveKeys(['message', 'role_revealed']);
});
