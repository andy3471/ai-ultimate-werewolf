<?php

namespace App\Services\GameSteps;

use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\States\GamePhase\DayVoting;

class DayDiscussionStepRunner
{
    public function run(Game $game, GameEngine $engine): bool
    {
        $alivePlayers = $game->alivePlayers()->get()->values();
        $playerCount = $alivePlayers->count();

        if ($playerCount === 0) {
            $engine->transitionToPhase($game, DayVoting::class);

            return true;
        }

        $plan = $engine->getOrCreateDiscussionPlan($game, $alivePlayers);
        $openingOrder = collect($plan['opening_order'] ?? [])->values();
        $totalBudget = (int) ($plan['total_budget'] ?? ($playerCount * 2));
        $maxSpeechesPerPlayer = 3;

        if ($game->phase_step < $openingOrder->count()) {
            $player = Player::find($openingOrder->get($game->phase_step));
            if ($player && $player->is_alive) {
                $isFirstRound = $game->round <= 1;
                $prompt = $isFirstRound
                    ? 'React to what happened last night. If someone died and made a claim, engage with it — take a clear position. Challenge someone or defend someone. Be direct and opinionated, not vague. You may address a specific player by setting addressed_player_id to their player number.'
                    : 'Share your thoughts for this round. Reflect on what happened — who acted suspiciously, how people voted, and any patterns you noticed. Be direct. You may address a specific player by setting addressed_player_id to their player number.';

                $engine->createDiscussionMessage($game, $player, $prompt);
            }

            return false;
        }

        $discussionCount = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'discussion')
            ->count();

        if ($discussionCount >= $totalBudget) {
            $engine->transitionToPhase($game, DayVoting::class);

            return true;
        }

        $speechCounts = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'discussion')
            ->reorder()
            ->selectRaw('actor_player_id, COUNT(*) as count')
            ->groupBy('actor_player_id')
            ->pluck('count', 'actor_player_id');

        $eligibleSpeakers = $alivePlayers->filter(function (Player $player) use ($speechCounts, $maxSpeechesPerPlayer) {
            return (int) ($speechCounts[$player->id] ?? 0) < $maxSpeechesPerPlayer;
        })->values();

        if ($eligibleSpeakers->isEmpty()) {
            $engine->transitionToPhase($game, DayVoting::class);

            return true;
        }

        $lastDiscussion = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'discussion')
            ->latest('id')
            ->first();

        $lastSpeakerId = $lastDiscussion?->actor_player_id;

        $speaker = $eligibleSpeakers
            ->sortBy(fn (Player $player) => (int) ($speechCounts[$player->id] ?? 0))
            ->first(fn (Player $player) => $player->id !== $lastSpeakerId)
            ?? $eligibleSpeakers->first();

        if (! $speaker) {
            $engine->transitionToPhase($game, DayVoting::class);

            return true;
        }

        $prompt = 'Continue the discussion. You may respond to what others have said, raise new points, ask someone a question (set addressed_player_id), or pass if you have nothing to add.';
        $engine->createDiscussionMessage($game, $speaker, $prompt);

        return false;
    }
}
