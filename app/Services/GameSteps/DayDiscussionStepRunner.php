<?php

namespace App\Services\GameSteps;

use App\Events\PlayerActed;
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
        $requestedExtensionBudget = (int) $game->events()
            ->where('round', $game->round)
            ->where('type', 'discussion_extension')
            ->get()
            ->sum(fn ($event) => (int) ($event->data['extra_budget'] ?? 0));
        $maxExtensionCap = $playerCount;
        $effectiveExtensionBudget = min($requestedExtensionBudget, $maxExtensionCap);
        $hardBudget = $baseBudget + $effectiveExtensionBudget;
        $passStreakLimit = min(3, max(2, (int) ceil($playerCount / 3)));
        $maxSpeechesPerPlayer = 3;

        $trialSparedSameDay = $game->events()
            ->where('round', $game->round)
            ->where('phase', 'day_voting')
            ->where('type', 'no_elimination')
            ->exists();

        if ($game->phase_step < $openingOrder->count()) {
            $player = Player::find($openingOrder->get($game->phase_step));
            if ($player && $player->is_alive) {
                $isFirstRound = $game->round <= 1;
                $isFirstOpeningSpeaker = $game->phase_step === 0;

                if ($isFirstRound && $isFirstOpeningSpeaker && $trialSparedSameDay) {
                    $prompt = <<<'PROMPT'
You are the first speaker as **discussion resumes** after a trial **spared** the accused. This is still the **same calendar day** — **no new night** has happened since the morning already shown in the log. Do NOT say "another peaceful night" or reset the conversation as if dawn just happened.
React to the **trial, defenses, and votes**, and how earlier discussion fits together. You may share what the village should do next.
Do NOT accuse anyone of being "quiet" or "not talking" about the trial — people need room to respond. Prefer addressed_player_id=0 unless you invite a specific follow-up on the trial.
Be direct and grounded in what actually appears in the game history.
PROMPT;
                } elseif ($isFirstRound && $isFirstOpeningSpeaker) {
                    $prompt = <<<'PROMPT'
You are the first speaker this day. No one has spoken in discussion yet.
If this is the first discussion after Night 1, remember: **werewolves do not kill on Night 1** — there is no werewolf victim yet unless the log shows otherwise. React only to **public dawn/history lines** (peaceful morning, death, saves, claims). Do not say "another peaceful night" when only one night has completed.
You may share an early plan for how the village should approach the day.
Do NOT accuse anyone of being "quiet", "not talking", or "lurking" — that is impossible to observe fairly before others have spoken. Do not invent social reads from silence.
You may set addressed_player_id only to ask a neutral opening question (not an accusation). Prefer addressed_player_id=0 unless you are directly inviting someone to share their night read.
Be direct but grounded in observable facts from the game state so far.
PROMPT;
                } elseif ($isFirstRound && $trialSparedSameDay) {
                    $prompt = 'Discussion continues **the same day** after the trial spared the accused — **no new night** since dawn. React to the trial, earlier opening speakers, and the vote tally. Do not pivot to "last night" or "peaceful night" as if a fresh overnight happened. You may address a specific player via addressed_player_id.';
                } elseif ($isFirstRound) {
                    $prompt = 'React to what happened last night and to what earlier opening speakers said. You may take a position, but do not accuse anyone of being "quiet" or "not participating" based on lack of discussion — only a few players have spoken so far. You may address a specific player by setting addressed_player_id to their player number.';
                } else {
                    $prompt = 'Share your thoughts for this round. Reflect on what happened — who acted suspiciously, how people voted, and any patterns you noticed. Be direct. You may address a specific player by setting addressed_player_id to their player number.';
                }

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

        $this->emitModeratorWarnings($game, $discussionCount, $hardBudget);

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

        $sameDayTrialSuffix = $trialSparedSameDay
            ? ' Same-day reminder: a trial already spared someone this round — there has been no new night since dawn; stay on trial and discussion evidence, not overnight fiction.'
            : '';

        $prompt = $addressedSpeaker
            ? 'You were directly addressed in the previous message. Respond naturally to that point first, then add anything else useful. You may address a specific player via addressed_player_id, or pass if you genuinely have nothing to add.'.$sameDayTrialSuffix
            : 'Continue the discussion. You may respond to what others have said, raise new points, ask someone a question (set addressed_player_id), or pass if you have nothing to add.'.$sameDayTrialSuffix;
        $this->dayActionService->createDiscussionMessage($game, $speaker, $prompt, $engine);

        return false;
    }

    /**
     * Emit closing vs final moderator cues with enough spacing that they do not land back-to-back
     * (previously both could fire within one discussion message of each other).
     */
    protected function emitModeratorWarnings(Game $game, int $discussionCount, int $hardBudget): void
    {
        $remaining = max(0, $hardBudget - $discussionCount);
        if ($remaining <= 0) {
            return;
        }

        $closingThreshold = max(4, (int) ceil($hardBudget * 0.35));
        $minDiscussionsAfterClosing = max(2, min(6, (int) floor($hardBudget / 4)));

        $phaseValue = $game->phase->getValue();

        $hasClosing = $game->events()
            ->where('round', $game->round)
            ->where('phase', $phaseValue)
            ->where('type', 'discussion_warning_closing')
            ->exists();

        if (! $hasClosing && $remaining <= $closingThreshold && $remaining > 1) {
            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $phaseValue,
                'type' => 'discussion_warning_closing',
                'data' => [
                    'message' => 'Moderator: Discussion time is running out. Prepare nominations now.',
                    'remaining_discussion_slots' => $remaining,
                ],
                'is_public' => true,
            ]);
            broadcast(new PlayerActed($game->id, $event->toData()));

            return;
        }

        $hasFinal = $game->events()
            ->where('round', $game->round)
            ->where('phase', $phaseValue)
            ->where('type', 'discussion_warning_final_call')
            ->exists();

        if ($hasFinal || $remaining > 1) {
            return;
        }

        $closingEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $phaseValue)
            ->where('type', 'discussion_warning_closing')
            ->latest('id')
            ->first();

        $discussionsAfterClosing = $closingEvent
            ? $game->events()
                ->where('round', $game->round)
                ->where('phase', $phaseValue)
                ->where('type', 'discussion')
                ->where('id', '>', $closingEvent->id)
                ->count()
            : $minDiscussionsAfterClosing;

        $forceFinal = $discussionCount >= max(0, $hardBudget - 1);
        if ($discussionsAfterClosing < $minDiscussionsAfterClosing && ! $forceFinal) {
            return;
        }

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $phaseValue,
            'type' => 'discussion_warning_final_call',
            'data' => [
                'message' => 'Moderator: Final call. Nomination window is about to close.',
                'remaining_discussion_slots' => $remaining,
            ],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $event->toData()));
    }
}
