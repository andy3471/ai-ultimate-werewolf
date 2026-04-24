<?php

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Models\Game;
use App\Models\Player;
use App\Roles\Role;
use App\Services\GameSetupService;
use App\Services\NightResolver;
use App\Services\RoleRegistry;
use App\Services\WinConditionResolver;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Night;
use App\States\GameStatus\Running;

test('night role pipeline order is Werewolf then Seer then Bodyguard', function () {
    $registry = app(RoleRegistry::class);
    $ordered = collect($registry->all())
        ->filter(fn (Role $role) => $role->hasNightAction() && $role->nightActionPipelineOrder() !== null)
        ->sortBy(fn (Role $role) => $role->nightActionPipelineOrder())
        ->map(fn (Role $role) => $role->id())
        ->values();

    expect($ordered->map(fn (GameRole $gr) => $gr->value)->all())->toBe([
        GameRole::Werewolf->value,
        GameRole::Seer->value,
        GameRole::Bodyguard->value,
    ]);
});

test('night resolver records bodyguard save when kill matches protection', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Night::getMorphClass(),
        'round' => 2,
    ]);

    $victim = Player::create([
        'game_id' => $game->id,
        'name' => 'Victim',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Villager->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 0,
    ]);

    $game->events()->create([
        'round' => 2,
        'phase' => Night::$name,
        'type' => 'werewolf_kill',
        'target_player_id' => $victim->id,
        'data' => [],
        'is_public' => false,
    ]);

    $game->events()->create([
        'round' => 2,
        'phase' => Night::$name,
        'type' => 'bodyguard_protect',
        'target_player_id' => $victim->id,
        'data' => [],
        'is_public' => false,
    ]);

    $result = app(NightResolver::class)->resolve($game);

    expect($result['killed'])->toBeNull();
    expect(collect($result['events'])->pluck('type')->all())->toContain('bodyguard_save');
    $victim->refresh();
    expect($victim->is_alive)->toBeTrue();
});

test('night resolver kills when werewolf target is not protected', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Night::getMorphClass(),
        'round' => 2,
    ]);

    $victim = Player::create([
        'game_id' => $game->id,
        'name' => 'Victim',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Villager->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 0,
    ]);

    $game->events()->create([
        'round' => 2,
        'phase' => Night::$name,
        'type' => 'werewolf_kill',
        'target_player_id' => $victim->id,
        'data' => [],
        'is_public' => false,
    ]);

    $result = app(NightResolver::class)->resolve($game);

    expect($result['killed']?->is($victim))->toBeTrue();
    expect(collect($result['events'])->pluck('type')->all())->toContain('death');
    $victim->refresh();
    expect($victim->is_alive)->toBeFalse();
});

test('win resolver uses teams for parity when neutral is alive', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => Night::getMorphClass(),
        'round' => 3,
    ]);

    Player::create([
        'game_id' => $game->id,
        'name' => 'Wolf',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Werewolf->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 0,
    ]);

    Player::create([
        'game_id' => $game->id,
        'name' => 'Tanner',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Tanner->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 1,
    ]);

    $resolved = app(WinConditionResolver::class)->resolve($game);

    expect($resolved)->toBeTrue();
    $game->refresh();
    expect($game->winner)->toBe(GameTeam::Werewolves);
});

test('win resolver awards village when all werewolves are dead', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
    ]);

    Player::create([
        'game_id' => $game->id,
        'name' => 'Villager',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Villager->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 0,
    ]);

    $resolved = app(WinConditionResolver::class)->resolve($game);

    expect($resolved)->toBeTrue();
    $game->refresh();
    expect($game->winner)->toBe(GameTeam::Village);
});

test('hunter pending elimination follow-up depends on hunter_shot event', function () {
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayVoting::getMorphClass(),
        'round' => 2,
    ]);

    $hunter = Player::create([
        'game_id' => $game->id,
        'name' => 'Hunter',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'role' => GameRole::Hunter->value,
        'is_alive' => true,
        'personality' => 'neutral',
        'order' => 0,
    ]);

    $registry = app(RoleRegistry::class);
    expect($registry->get(GameRole::Hunter)->pendingEliminationFollowUp($game, $hunter))->toBeTrue();

    $game->events()->create([
        'round' => 2,
        'phase' => DayVoting::$name,
        'type' => 'hunter_shot',
        'actor_player_id' => $hunter->id,
        'data' => [],
        'is_public' => true,
    ]);

    expect($registry->get(GameRole::Hunter)->pendingEliminationFollowUp($game, $hunter))->toBeFalse();
});

test('standard deck composition matches legacy shape for six and seven players', function () {
    $svc = app(GameSetupService::class);
    $method = (new ReflectionClass($svc))->getMethod('distributeRoles');
    $method->setAccessible(true);

    /** @var GameRole[] $six */
    $six = $method->invoke($svc, 6);
    expect(count($six))->toBe(6);
    $sixCounts = array_count_values(array_map(fn (GameRole $r) => $r->value, $six));
    expect($sixCounts[GameRole::Werewolf->value])->toBe(1);
    expect($sixCounts[GameRole::Villager->value])->toBe(2);

    /** @var GameRole[] $seven */
    $seven = $method->invoke($svc, 7);
    expect(count($seven))->toBe(7);
    $sevenCounts = array_count_values(array_map(fn (GameRole $r) => $r->value, $seven));
    expect($sevenCounts[GameRole::Werewolf->value])->toBe(2);
    expect($sevenCounts[GameRole::Tanner->value])->toBe(1);
    expect($sevenCounts[GameRole::Villager->value])->toBe(1);
});
