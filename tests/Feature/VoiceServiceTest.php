<?php

use App\Models\Game;
use App\Models\Player;
use App\Services\VoiceService;

it('assigns deterministic openai voices when only openai is configured', function () {
    config([
        'ai.providers.eleven.key' => null,
        'ai.providers.openai.key' => 'test-openai-key',
    ]);

    $game = Game::factory()->create();
    Player::factory()->for($game)->order(0)->create(['name' => 'Alpha']);
    Player::factory()->for($game)->order(1)->create(['name' => 'Beta']);

    app(VoiceService::class)->assignVoices($game);

    $voices = $game->fresh()->players()->orderBy('order')->pluck('voice');

    expect($voices->filter()->count())->toBe(2)
        ->and($voices->unique()->count())->toBe(2);
});

it('skips voice assignment when no voice provider key is configured', function () {
    config([
        'ai.providers.eleven.key' => null,
        'ai.providers.openai.key' => null,
    ]);

    $game = Game::factory()->create();
    Player::factory()->for($game)->create(['name' => 'Silent']);

    app(VoiceService::class)->assignVoices($game);

    expect($game->fresh()->players()->first()->voice)->toBeNull();
});

it('reports availability from configured providers', function () {
    config([
        'ai.providers.eleven.key' => null,
        'ai.providers.openai.key' => 'test-openai-key',
    ]);

    expect(app(VoiceService::class)->isAvailable())->toBeTrue();

    config(['ai.providers.openai.key' => null]);

    expect(app(VoiceService::class)->isAvailable())->toBeFalse();
});
