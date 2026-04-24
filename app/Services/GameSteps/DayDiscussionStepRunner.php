<?php

namespace App\Services\GameSteps;

use App\Models\Game;
use App\Models\Player;
use App\Services\DayActionService;
use App\Services\GameEngine;
use App\States\GamePhase\DayVoting;

class DayDiscussionStepRunner
{
    public function __construct(
        protected DayActionService $dayActionService,
    ) {}

    public function run(Game $game, GameEngine $engine): bool
    {
        $alivePlayers = $game->alivePlayers()->get()->values();
        $playerCount = $alivePlayers->count();

        if ($playerCount === 0) {
            $engine->transitionToPhase($game, DayVoting::class);

            return true;
        }

        $plan = $this->dayActionService->getOrCreateDiscussionPlan($game, $alivePlayers);
        $openingOrder = collect($plan['opening_order'] ?? [])->values();
        $baseBudget = (int) ($plan['total_budget'] ?? ($playerCount * 2));
        $extensionBudget = max(1, (int) floor($playerCount / 2));
        $hardBudget = $baseBudget + $extensionBudget;
        $passStreakLimit = min(3, max(2, (int) ceil($playerCount / 3)));
        $maxSpeechesPerPlayer = 3;

        if ($game->phase_step < $openingOrder->count()) {
            $player = Player::find($openingOrder->get($game->phase_step));
            if ($player && $player->is_alive) {
                $isFirstRound = $game->round <= 1;
                $prompt = $isFirstRound
                    ? 'React to what happened last night. If someone died and made a claim, engage with it — take a clear position. Challenge someone or defend someone. Be direct and opinionated, not vague. You may address a specific player by setting addressed_player_id to their player number.'
                    : 'Share your thoughts for this round. Reflect on what happened — who acted suspiciously, how people voted, and any patterns you noticed. Be direct. You may address a specific player by setting addressed_player_id to their player number.';

                $this->dayActionService->createDiscussionMessage($game, $player, $prompt, $engine);
            }

            return false;
        }

        $discussionCount = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'discussion')
            ->count();

        $passStreak = (int) $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->whereIn('type', ['discussion', 'discussion_pass'])
            ->orderByDesc('id')
            ->limit($passStreakLimit)
            ->get()
            ->takeWhile(fn ($event) => $event->type === 'discussion_pass')
            ->count();

        if ($passStreak >= $passStreakLimit) {
            $engine->transitionToPhase($game, DayVoting::class);

            return true;
        }

        if ($discussionCount >= $baseBudget) {
            $lastDiscussionEvent = $game->events()
                ->where('round', $game->round)
                ->where('phase', $game->phase->getValue())
                ->where('type', 'discussion')
                ->latest('id')
                ->first();

            $hasDirectReplyThread = ! empty($lastDiscussionEvent?->data['addressed_player_id']);
            $looksAccusatory = str_contains(strtolower((string) ($lastDiscussionEvent?->data['message'] ?? '')), 'werewolf')
                || str_contains(strtolower((string) ($lastDiscussionEvent?->data['message'] ?? '')), 'suspicious');

            if (! $hasDirectReplyThread && ! $looksAccusatory) {
                $engine->transitionToPhase($game, DayVoting::class);

                return true;
            }
        }

        if ($discussionCount >= $hardBudget) {
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
        $addressedPlayerId = $lastDiscussion?->data['addressed_player_id'] ?? null;

        $addressedSpeaker = $addressedPlayerId
            ? $eligibleSpeakers->first(fn (Player $player) => $player->id === $addressedPlayerId && $player->id !== $lastSpeakerId)
            : null;

        $speaker = $addressedSpeaker
            ?? $eligibleSpeakers
                ->sortBy(fn (Player $player) => (int) ($speechCounts[$player->id] ?? 0))
                ->first(fn (Player $player) => $player->id !== $lastSpeakerId)
            ?? $eligibleSpeakers->first();

        if (! $speaker) {
            $engine->transitionToPhase($game, DayVoting::class);

            return true;
        }

        $prompt = $addressedSpeaker
            ? 'You were directly addressed in the previous message. Respond naturally to that point first, then add anything else useful. You may address a specific player via addressed_player_id, or pass if you genuinely have nothing to add.'
            : 'Continue the discussion. You may respond to what others have said, raise new points, ask someone a question (set addressed_player_id), or pass if you have nothing to add.';
        $this->dayActionService->createDiscussionMessage($game, $speaker, $prompt, $engine);

        return false;
    }
}
