<?php

use App\Ai\Agents\DiscussionAgent;
use App\Enums\GameRole;
use App\Jobs\RunGame;
use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GameStatus\Running;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Call the protected processDayDiscussion method on GameEngine.
 */
function runDayDiscussion(GameEngine $engine, Game $game): void
{
    $ref = new ReflectionMethod($engine, 'processDayDiscussion');
    $ref->invoke($engine, $game);
}

/**
 * Create a game in the day_discussion phase with the given number of players.
 */
function createGameInDiscussion(int $playerCount = 5): Game
{
    $game = Game::factory()->create([
        'status' => Running::getMorphClass(),
        'phase' => DayDiscussion::getMorphClass(),
        'round' => 1,
        'role_distribution' => ['Werewolf' => 1, 'Seer' => 1, 'Villager' => $playerCount - 2],
    ]);

    $roles = [GameRole::Werewolf, GameRole::Seer];
    for ($i = 2; $i < $playerCount; $i++) {
        $roles[] = GameRole::Villager;
    }

    foreach ($roles as $i => $role) {
        Player::create([
            'game_id' => $game->id,
            'name' => "Player ".($i + 1),
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'role' => $role->value,
            'is_alive' => true,
            'personality' => 'Neutral',
            'order' => $i,
        ]);
    }

    return $game;
}

test('opening statements use frozen context so speakers do not see each other', function () {
    $game = createGameInDiscussion(5);

    $promptCount = 0;
    $discussionEventsAtPromptTime = [];

    DiscussionAgent::fake(function (string $prompt) use ($game, &$promptCount, &$discussionEventsAtPromptTime) {
        $promptCount++;
        // Record how many discussion events exist at the time each prompt is sent.
        // With frozen context, no discussion events should be saved until after all opening prompts.
        $discussionEventsAtPromptTime[] = $game->events()->where('type', 'discussion')->count();

        return [
            'thinking' => 'test',
            'message' => 'I have nothing to report.',
            'addressed_player_id' => 0,
            'wants_to_speak' => true,
        ];
    });

    Event::fake();

    $engine = app(GameEngine::class);
    runDayDiscussion($engine, $game);

    // The first 5 prompts are opening statements. With frozen context,
    // no discussion events should exist in the DB when any of them are prompted.
    foreach ($discussionEventsAtPromptTime as $i => $count) {
        if ($i < 5) {
            expect($count)->toBe(0, "Opening speaker #{$i} saw {$count} discussion events (expected 0)");
        }
    }
});

test('total discussion messages do not exceed budget of playerCount * 2', function () {
    $game = createGameInDiscussion(5);

    DiscussionAgent::fake(function () {
        return [
            'thinking' => 'test',
            'message' => 'Something to say.',
            'addressed_player_id' => 0,
            'wants_to_speak' => true,
        ];
    });

    Event::fake();

    $engine = app(GameEngine::class);
    runDayDiscussion($engine, $game);

    $discussionCount = $game->events()->where('type', 'discussion')->count();

    // Budget = 5 * 2 = 10
    expect($discussionCount)->toBeLessThanOrEqual(10);
});

test('no single player can speak more than 3 times', function () {
    $game = createGameInDiscussion(7);

    // Every player addresses player 1, which would cause them to be pulled
    // from the response queue repeatedly without a cap.
    $firstPlayer = $game->players()->where('order', 0)->first();

    DiscussionAgent::fake(function () use ($firstPlayer) {
        return [
            'thinking' => 'test',
            'message' => 'Pointing at player 1.',
            'addressed_player_id' => $firstPlayer->order + 1,
            'wants_to_speak' => true,
        ];
    });

    Event::fake();

    $engine = app(GameEngine::class);
    runDayDiscussion($engine, $game);

    $speechCounts = $game->events()
        ->where('type', 'discussion')
        ->get()
        ->groupBy('actor_player_id')
        ->map->count();

    foreach ($speechCounts as $playerId => $count) {
        expect($count)->toBeLessThanOrEqual(3, "Player {$playerId} spoke {$count} times (max 3)");
    }
});

test('discussion transitions to day voting phase', function () {
    $game = createGameInDiscussion(5);

    DiscussionAgent::fake(function () {
        return [
            'thinking' => 'test',
            'message' => 'Let us vote.',
            'addressed_player_id' => 0,
            'wants_to_speak' => false,
        ];
    });

    Event::fake();

    $engine = app(GameEngine::class);
    runDayDiscussion($engine, $game);

    $game->refresh();
    expect($game->phase)->toBeInstanceOf(DayVoting::class);
});

test('RunGame job implements ShouldBeUnique', function () {
    $game = Game::factory()->create();
    $job = new RunGame($game);

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class);
    expect($job->uniqueId())->toBe($game->id);
});

test('RunGame job has tries set to 1', function () {
    $game = Game::factory()->create();
    $job = new RunGame($game);

    expect($job->tries)->toBe(1);
});
