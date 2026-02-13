<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use Illuminate\Support\Collection;

class VoteResolver
{
    /**
     * Resolve the day vote for the given round.
     * Returns the eliminated player (or null on a tie).
     *
     * @return array{eliminated: Player|null, tally: array<int, int>, events: GameEvent[]}
     */
    public function resolve(Game $game): array
    {
        $round = $game->round;
        $events = [];

        // Gather all vote events for this round
        $votes = $game->events()
            ->where('round', $round)
            ->where('type', 'vote')
            ->get();

        // Tally votes: target_player_id => count
        $tally = $votes
            ->groupBy('target_player_id')
            ->map(fn (Collection $group) => $group->count())
            ->sortDesc()
            ->all();

        // Record the vote tally
        $tallyEvent = $game->events()->create([
            'round' => $round,
            'phase' => 'dusk',
            'type' => 'vote_tally',
            'data' => [
                'tally' => $tally,
                'message' => $this->formatTally($tally, $game),
            ],
            'is_public' => true,
        ]);
        $events[] = $tallyEvent;

        // Determine the result
        $topVotes = collect($tally);
        $maxVotes = $topVotes->first();

        if ($maxVotes === null || $maxVotes === 0) {
            $events[] = $game->events()->create([
                'round' => $round,
                'phase' => 'dusk',
                'type' => 'no_elimination',
                'data' => ['message' => 'No votes were cast. No one is eliminated.'],
                'is_public' => true,
            ]);

            return ['eliminated' => null, 'tally' => $tally, 'events' => $events];
        }

        // Check for ties
        $topCandidates = $topVotes->filter(fn (int $count) => $count === $maxVotes);

        if ($topCandidates->count() > 1) {
            $events[] = $game->events()->create([
                'round' => $round,
                'phase' => 'dusk',
                'type' => 'vote_tie',
                'data' => ['message' => 'The vote ended in a tie! No one is eliminated.'],
                'is_public' => true,
            ]);

            return ['eliminated' => null, 'tally' => $tally, 'events' => $events];
        }

        // Eliminate the player with the most votes
        $eliminatedId = $topCandidates->keys()->first();
        $eliminated = Player::find($eliminatedId);

        if ($eliminated) {
            $eliminated->update(['is_alive' => false]);

            $events[] = $game->events()->create([
                'round' => $round,
                'phase' => 'dusk',
                'type' => 'elimination',
                'target_player_id' => $eliminated->id,
                'data' => [
                    'message' => "{$eliminated->name} has been eliminated by the village. Their role is confirmed: {$eliminated->role->value}.",
                    'role_revealed' => $eliminated->role->value,
                    'votes_received' => $maxVotes,
                ],
                'is_public' => true,
            ]);
        }

        return [
            'eliminated' => $eliminated,
            'tally' => $tally,
            'events' => $events,
        ];
    }

    /**
     * Format the vote tally as a human-readable string.
     */
    protected function formatTally(array $tally, Game $game): string
    {
        if (empty($tally)) {
            return 'No votes were cast.';
        }

        $players = $game->players->keyBy('id');
        $lines = [];

        foreach ($tally as $playerId => $count) {
            $player = $players->get($playerId);
            $name = $player ? $player->name : "Player #{$playerId}";
            $lines[] = "{$name}: {$count} vote".($count !== 1 ? 's' : '');
        }

        return implode(', ', $lines);
    }
}
