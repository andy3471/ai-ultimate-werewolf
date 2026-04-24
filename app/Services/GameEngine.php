<?php

namespace App\Services;

use App\Ai\Agents\DiscussionAgent;
use App\Ai\Agents\NightActionAgent;
use App\Ai\Agents\VoteAgent;
use App\Ai\Context\GameContext;
use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Events\GameEnded;
use App\Events\GamePhaseChanged;
use App\Events\PlayerActed;
use App\Events\PlayerEliminated;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Services\GameSteps\DayDiscussionStepRunner;
use App\Services\GameSteps\DayVotingStepRunner;
use App\States\GamePhase\Dawn;
use App\States\GamePhase\DayDiscussion;
use App\States\GamePhase\DayVoting;
use App\States\GamePhase\Dusk;
use App\States\GamePhase\GameOver;
use App\States\GamePhase\NightBodyguard;
use App\States\GamePhase\NightSeer;
use App\States\GamePhase\NightWerewolf;
use App\States\GameStatus\Finished;
use App\States\GameStatus\Running;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GameEngine
{
    protected const PHASE_DELAY_SECONDS = 2;

    protected const MAX_ROUNDS = 20;

    public function __construct(
        protected RoleRegistry $roleRegistry,
        protected NightResolver $nightResolver,
        protected VoteResolver $voteResolver,
        protected GameContext $gameContext,
        protected VoiceService $voiceService,
        protected DayDiscussionStepRunner $dayDiscussionStepRunner,
        protected DayVotingStepRunner $dayVotingStepRunner,
    ) {}

    /**
     * Assign roles to players and start the game.
     */
    public function startGame(Game $game): void
    {
        $game->refresh();

        // Already running — skip setup (idempotent for retries)
        if ($game->status instanceof Running) {
            return;
        }

        $players = $game->players()->get();
        $playerCount = $players->count();

        // Determine role distribution (scales with player count)
        $roles = $this->distributeRoles($playerCount);
        $roleDistribution = $this->buildRoleDistribution($roles);

        // Shuffle and assign
        $shuffledRoles = collect($roles)->shuffle();

        foreach ($players as $index => $player) {
            $player->update(['role' => $shuffledRoles[$index]]);
        }

        // Assign voices for TTS
        $this->voiceService->assignVoices($game);

        // Transition game state
        $game->status->transitionTo(Running::class);
        $game->update(['round' => 1, 'phase_step' => 0, 'role_distribution' => $roleDistribution]);
        $game->phase->transitionTo(NightWerewolf::class);

        $this->broadcastPhaseChange($game);
    }

    /**
     * Execute one game step and return next-step metadata.
     *
     * @return array{delay_seconds: int}
     */
    public function runStep(Game $game): array
    {
        $this->pendingDelaySeconds = 0;
        $game->refresh();

        if (
            $game->phase instanceof GameOver
            || $game->status instanceof Finished
            || $game->round > self::MAX_ROUNDS
        ) {
            return ['delay_seconds' => 0];
        }

        try {
            $phaseTransitioned = match (true) {
                $game->phase instanceof NightWerewolf => $this->runNightWerewolfStep($game),
                $game->phase instanceof NightSeer => $this->runNightSeerStep($game),
                $game->phase instanceof NightBodyguard => $this->runNightBodyguardStep($game),
                $game->phase instanceof Dawn => $this->runDawnStep($game),
                $game->phase instanceof DayDiscussion => $this->runDayDiscussionStep($game),
                $game->phase instanceof DayVoting => $this->runDayVotingStep($game),
                $game->phase instanceof Dusk => $this->runDuskStep($game),
                default => true,
            };

            if (! $phaseTransitioned) {
                $this->advancePhaseStep($game);
            }
        } catch (\Throwable $e) {
            Log::error('GameEngine error', [
                'game_id' => $game->id,
                'phase' => $game->phase->getValue(),
                'round' => $game->round,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'error',
                'data' => ['message' => 'An error occurred: '.$e->getMessage()],
                'is_public' => true,
            ]);
        }

        return ['delay_seconds' => max(self::PHASE_DELAY_SECONDS, $this->pendingDelaySeconds)];
    }

    public function runCurrentPhase(Game $game): int
    {
        return $this->runStep($game)['delay_seconds'];
    }

    public function maxRounds(): int
    {
        return self::MAX_ROUNDS;
    }

    protected function runNightWerewolfStep(Game $game): bool
    {
        $werewolves = $game->alivePlayers()
            ->where('role', GameRole::Werewolf->value)
            ->get()
            ->values();

        if ($werewolves->isEmpty()) {
            $this->transitionToPhase($game, NightSeer::class);

            return true;
        }

        if ($game->phase_step < $werewolves->count()) {
            $werewolf = $werewolves->get($game->phase_step);
            $this->processWerewolfProposal($game, $werewolf);

            return false;
        }

        $this->resolveWerewolfTarget($game);
        $this->transitionToPhase($game, NightSeer::class);

        return true;
    }

    protected function runNightSeerStep(Game $game): bool
    {
        if ($game->phase_step > 0) {
            return true;
        }

        $this->processNightSeer($game);

        return true;
    }

    protected function runNightBodyguardStep(Game $game): bool
    {
        if ($game->phase_step > 0) {
            return true;
        }

        $this->processNightBodyguard($game);

        return true;
    }

    protected function runDawnStep(Game $game): bool
    {
        $resolution = $this->getOrCreateDawnResolution($game);
        $dawnEvents = $game->events()
            ->where('round', $game->round)
            ->where('phase', Dawn::$name)
            ->whereIn('type', ['death', 'bodyguard_save', 'no_death'])
            ->orderBy('id')
            ->get()
            ->values();

        if ($game->phase_step < $dawnEvents->count()) {
            $event = $dawnEvents->get($game->phase_step);

            if ($event->type === 'death') {
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $event->target_player_id,
                    $event->data['role_revealed'] ?? 'unknown',
                ));
            } else {
                broadcast(new PlayerActed($game->id, $event->toData()));
            }

            $this->addDelaySeconds(2);

            return false;
        }

        $stepAfterBroadcasts = (int) $dawnEvents->count();
        if ($game->phase_step === $stepAfterBroadcasts) {
            $this->generateAndBroadcastNarration($game);

            return false;
        }

        $killedPlayerId = $resolution['killed_player_id'] ?? null;
        $killedPlayer = $killedPlayerId ? Player::find($killedPlayerId) : null;

        if ($killedPlayer) {
            if ($game->phase_step === $stepAfterBroadcasts + 1) {
                $this->giveDyingSpeech($game, $killedPlayer);

                return false;
            }

            if ($game->phase_step === $stepAfterBroadcasts + 2) {
                $this->processHunterRevengeShot($game, $killedPlayer);

                return false;
            }

            if ($game->phase_step === $stepAfterBroadcasts + 3) {
                $shotEvent = $game->events()
                    ->where('round', $game->round)
                    ->where('phase', $game->phase->getValue())
                    ->where('type', 'hunter_shot')
                    ->latest('id')
                    ->first();

                if ($shotEvent && $shotEvent->target_player_id) {
                    $shotPlayer = Player::find($shotEvent->target_player_id);
                    if ($shotPlayer) {
                        $this->giveDyingSpeech($game, $shotPlayer);
                    }
                }

                return false;
            }
        }

        if ($this->checkWinCondition($game)) {
            return true;
        }

        $this->transitionToPhase($game, DayDiscussion::class);

        return true;
    }

    protected function runDayDiscussionStep(Game $game): bool
    {
        return $this->dayDiscussionStepRunner->run($game, $this);
    }

    protected function runDayVotingStep(Game $game): bool
    {
        return $this->dayVotingStepRunner->run($game, $this);
    }

    protected function runDuskStep(Game $game): bool
    {
        if ($game->phase_step > 0) {
            return true;
        }

        $this->processDusk($game);

        return true;
    }

    // =========================================================================
    // Night Phases
    // =========================================================================

    /**
     * Werewolves coordinate and choose a single victim.
     * Wolves see each other's proposals to reach consensus.
     */
    protected function processNightWerewolf(Game $game): void
    {
        $werewolves = $game->alivePlayers()
            ->where('role', GameRole::Werewolf->value)
            ->get();

        $proposals = [];

        foreach ($werewolves as $index => $werewolf) {
            // Build context — include previous wolves' proposals for consensus
            $context = $this->gameContext->buildForPlayer($game, $werewolf);

            if (! empty($proposals)) {
                $context .= "\n\n## Werewolf Pack Discussion\n";
                $context .= "Your fellow wolves have already proposed targets:\n";
                foreach ($proposals as $proposal) {
                    $context .= "- {$proposal['name']} wants to kill {$proposal['target_name']}: \"{$proposal['reasoning']}\"\n";
                }
                $context .= "\nConsider agreeing with your pack for a coordinated attack, unless you have a strong reason to disagree.";
            }

            $role = $this->roleRegistry->get($werewolf->role);

            $result = NightActionAgent::make(
                player: $werewolf,
                game: $game,
                context: $context,
                actionPrompt: $role->nightActionPrompt(),
            )->prompt(
                'Choose your target for tonight.',
                provider: $werewolf->provider,
                model: $werewolf->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $werewolf->id);

            $targetPlayer = $targetId ? Player::find($targetId) : null;
            $proposals[] = [
                'wolf_id' => $werewolf->id,
                'name' => $werewolf->name,
                'target_id' => $targetId,
                'target_name' => $targetPlayer?->name ?? 'unknown',
                'reasoning' => $result['public_reasoning'] ?? '',
            ];

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'werewolf_kill',
                'actor_player_id' => $werewolf->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $result['public_reasoning'] ?? '',
                ],
                'is_public' => false,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));
        }

        // Determine final target: majority, or first wolf's choice on tie
        $finalTarget = collect($proposals)
            ->pluck('target_id')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        // Consolidate all wolf events to the agreed target
        $game->events()
            ->where('round', $game->round)
            ->where('type', 'werewolf_kill')
            ->update(['target_player_id' => $finalTarget]);

        $this->transitionToPhase($game, NightSeer::class);
    }

    protected function processNightSeer(Game $game): void
    {
        $seer = $game->alivePlayers()
            ->where('role', GameRole::Seer->value)
            ->first();

        if ($seer) {
            $context = $this->gameContext->buildForPlayer($game, $seer);
            $role = $this->roleRegistry->get($seer->role);

            $result = NightActionAgent::make(
                player: $seer,
                game: $game,
                context: $context,
                actionPrompt: $role->nightActionPrompt(),
            )->prompt(
                'Choose a player to investigate.',
                provider: $seer->provider,
                model: $seer->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $seer->id);
            $target = Player::find($targetId);

            $investigationResult = $target
                ? $role->describeNightResult($seer, $target, $game)
                : 'The investigation revealed nothing.';

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'seer_investigate',
                'actor_player_id' => $seer->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $result['public_reasoning'] ?? '',
                    'result' => $investigationResult,
                ],
                'is_public' => false,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));
        }

        $this->transitionToPhase($game, NightBodyguard::class);
    }

    /**
     * Bodyguard protects a player. Cannot protect the same player two nights in a row.
     */
    protected function processNightBodyguard(Game $game): void
    {
        $bodyguard = $game->alivePlayers()
            ->where('role', GameRole::Bodyguard->value)
            ->first();

        if ($bodyguard) {
            // Find who was protected last round (consecutive protection rule)
            $lastProtection = $game->events()
                ->where('type', 'bodyguard_protect')
                ->where('actor_player_id', $bodyguard->id)
                ->where('round', $game->round - 1)
                ->first();

            $lastProtectedId = $lastProtection?->target_player_id;
            $lastProtectedName = $lastProtectedId
                ? Player::find($lastProtectedId)?->name ?? 'Unknown'
                : null;

            $context = $this->gameContext->buildForPlayer($game, $bodyguard);

            // Add consecutive protection constraint to context
            if ($lastProtectedName) {
                $context .= "\n\n## Bodyguard Restriction\nYou protected **{$lastProtectedName}** (ID: {$lastProtectedId}) last night. You CANNOT protect them again tonight. You must choose a different player.";
            }

            $role = $this->roleRegistry->get($bodyguard->role);

            $result = NightActionAgent::make(
                player: $bodyguard,
                game: $game,
                context: $context,
                actionPrompt: $role->nightActionPrompt(),
            )->prompt(
                'Choose a player to protect tonight.',
                provider: $bodyguard->provider,
                model: $bodyguard->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game);

            // Enforce consecutive protection rule server-side
            if ($targetId === $lastProtectedId) {
                // Pick a random different alive player
                $targetId = $game->alivePlayers()
                    ->where('id', '!=', $lastProtectedId)
                    ->inRandomOrder()
                    ->first()?->id;
            }

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'bodyguard_protect',
                'actor_player_id' => $bodyguard->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $result['public_reasoning'] ?? '',
                ],
                'is_public' => false,
            ]);

            broadcast(new PlayerActed($game->id, $event->toData()));
        }

        // Don't narrate dawn yet — we need to resolve night actions first
        // so the narrator knows who died. Narration happens in processDawn().
        $this->transitionToPhase($game, Dawn::class, narrate: false);
    }

    // =========================================================================
    // Dawn — resolve night actions, dying speech for killed player
    // =========================================================================

    protected function processDawn(Game $game): void
    {
        $result = $this->nightResolver->resolve($game);

        // Broadcast death/save events with a brief pause so the log
        // doesn't scroll past them before the user can read them
        foreach ($result['events'] as $event) {
            if ($event->type === 'death') {
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $event->target_player_id,
                    $event->data['role_revealed'] ?? 'unknown',
                ));
            } else {
                broadcast(new PlayerActed($game->id, $event->toData()));
            }
            $this->addDelaySeconds(2);
        }

        // NOW narrate dawn — the death/save events exist so the narrator
        // knows who died or was saved
        $this->generateAndBroadcastNarration($game);

        // Dying speech + Hunter revenge for the killed player
        if ($result['killed']) {
            $this->giveDyingSpeech($game, $result['killed']);
            $this->handleHunterRevenge($game, $result['killed']);
        }

        // Check win condition
        if ($this->checkWinCondition($game)) {
            return;
        }

        $game->phase->transitionTo(DayDiscussion::class);
        $this->broadcastPhaseChange($game);
    }

    // =========================================================================
    // Day Phases — discussion, nomination, trial, voting
    // =========================================================================

    /**
     * Dynamic discussion: opening round + open-floor with directed questions.
     *
     * 1. Round 1: Every alive player speaks once (randomized order). They may
     *    address a question to a specific player.
     * 2. Open floor: Up to (playerCount * 2) additional turns. If someone was
     *    addressed, they get priority next. Otherwise, weighted-random pick
     *    (players who have spoken less get higher weight). Players may pass.
     * 3. Stops early if all remaining players pass consecutively.
     *
     * Total budget: playerCount * 2 statements (max 3 per player).
     */
    protected function processDayDiscussion(Game $game): void
    {
        $alivePlayers = $game->alivePlayers()->get();
        $playerCount = $alivePlayers->count();
        $totalBudget = $playerCount * 2;
        $maxSpeechesPerPlayer = 3;

        // Track how many times each player has spoken (plain array — Collection doesn't support $col[$key]++)
        $speechCounts = $alivePlayers->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all();

        // Response queue: [['player_id' => int, 'from_name' => string], ...]
        // Players addressed by someone who should get next turn
        $responseQueue = collect();

        $statementsMade = 0;
        $consecutivePasses = 0;

        // --- Round 1: Opening statements (everyone speaks once, shuffled) ---
        // Freeze context per player BEFORE the loop so opening speakers react
        // only to night events, not each other's messages.
        $shuffled = $alivePlayers->shuffle();
        $frozenContexts = [];
        foreach ($shuffled as $player) {
            $frozenContexts[$player->id] = $this->gameContext->buildForPlayer($game, $player);
        }

        $isFirstRound = $game->round <= 1;
        $openingPrompt = $isFirstRound
            ? 'React to what happened last night. If someone died and made a claim, engage with it — take a clear position. Challenge someone or defend someone. Be direct and opinionated, not vague. You may address a specific player by setting addressed_player_id to their player number.'
            : 'Share your thoughts for this round. Reflect on what happened — who acted suspiciously, how people voted, and any patterns you noticed. Be direct. You may address a specific player by setting addressed_player_id to their player number.';

        $pendingOpeningEvents = [];

        foreach ($shuffled as $player) {
            if ($statementsMade >= $totalBudget) {
                break;
            }

            $result = DiscussionAgent::make(
                player: $player,
                game: $game,
                context: $frozenContexts[$player->id],
            )->prompt(
                $openingPrompt,
                provider: $player->provider,
                model: $player->model,
            );

            // In round 1, ignore wants_to_speak — everyone must speak
            $message = $result['message'] ?? (string) $result;
            $addressedId = $this->resolveAddressedPlayerId($result['addressed_player_id'] ?? 0, $game, $player->id);

            $pendingOpeningEvents[] = compact('player', 'message', 'result', 'addressedId');

            $speechCounts[$player->id]++;
            $statementsMade++;
        }

        // Save and broadcast all opening statements now so the open-floor
        // phase (and future context builds) can see them.
        foreach ($pendingOpeningEvents as $pending) {
            $event = $this->recordDiscussionEvent($game, $pending['player'], $pending['message'], $pending['result'], $pending['addressedId']);
            broadcast(new PlayerActed($game->id, $event->toData()));

            if ($pending['addressedId']) {
                $responseQueue->push(['player_id' => $pending['addressedId'], 'from_name' => $pending['player']->name]);
            }

            $this->waitForAudio();
        }

        // --- Open floor: directed Q&A + weighted random ---
        $lastSpeakerId = null;           // prevent same player twice in a row
        $lastResponseWasQueued = false;   // force a random turn between queued responses

        while ($statementsMade < $totalBudget && $consecutivePasses < $playerCount) {
            // Pick next speaker: alternate between response queue and weighted random
            // to prevent two players ping-ponging indefinitely
            $nextPlayer = null;
            $addressedByName = null;

            // Only pull from response queue if the last turn was NOT also a queued response
            if (! $lastResponseWasQueued) {
                while ($responseQueue->isNotEmpty() && ! $nextPlayer) {
                    $entry = $responseQueue->shift();
                    $candidate = $alivePlayers->firstWhere('id', $entry['player_id']);
                    // Skip if this player just spoke (would cause back-and-forth)
                    if ($candidate && $candidate->id !== $lastSpeakerId) {
                        $nextPlayer = $candidate;
                        $addressedByName = $entry['from_name'];
                    }
                }
            }

            // Weighted random: players who spoke less get higher weight
            if (! $nextPlayer) {
                $maxSpeeches = max(1, max($speechCounts));
                $weights = array_map(fn (int $count) => max(0, $maxSpeeches - $count + 1), $speechCounts);

                // Exclude players who have hit the per-player speech cap
                foreach ($speechCounts as $pid => $count) {
                    if ($count >= $maxSpeechesPerPlayer) {
                        $weights[$pid] = 0;
                    }
                }

                // Exclude the last speaker from random pick to avoid repeats
                if ($lastSpeakerId && isset($weights[$lastSpeakerId])) {
                    $weights[$lastSpeakerId] = 0;
                }

                $nextPlayer = $this->weightedRandomPick($alivePlayers, $weights);
            }

            if (! $nextPlayer) {
                break;
            }

            // Skip players who have hit the per-player speech cap
            if ($speechCounts[$nextPlayer->id] >= $maxSpeechesPerPlayer) {
                $lastSpeakerId = $nextPlayer->id;
                $lastResponseWasQueued = (bool) $addressedByName;

                continue;
            }

            $context = $this->gameContext->buildForPlayer($game, $nextPlayer);

            // Build a prompt that tells the player if they were directly addressed
            $prompt = $addressedByName
                ? "{$addressedByName} just addressed you directly — respond to their point or question. You may also raise new points or address someone else (set addressed_player_id)."
                : 'Continue the discussion. You may respond to what others have said, raise new points, ask someone a question (set addressed_player_id), or pass if you have nothing to add.';

            $result = DiscussionAgent::make(
                player: $nextPlayer,
                game: $game,
                context: $context,
            )->prompt(
                $prompt,
                provider: $nextPlayer->provider,
                model: $nextPlayer->model,
            );

            $wantsToSpeak = $result['wants_to_speak'] ?? true;

            if (! $wantsToSpeak) {
                $consecutivePasses++;
                $lastSpeakerId = $nextPlayer->id;
                $lastResponseWasQueued = (bool) $addressedByName;

                continue;
            }

            // Reset consecutive pass counter
            $consecutivePasses = 0;

            $message = $result['message'] ?? (string) $result;
            $addressedId = $this->resolveAddressedPlayerId($result['addressed_player_id'] ?? 0, $game, $nextPlayer->id);

            // Prevent ping-pong: don't queue the person who just addressed us
            if ($addressedId && $addressedByName) {
                // Find who addressed us — don't let them be re-queued
                $addresserPlayer = $alivePlayers->first(fn ($p) => $p->name === $addressedByName);
                if ($addresserPlayer && $addressedId === $addresserPlayer->id) {
                    $addressedId = null; // break the loop — don't re-queue the asker
                }
            }

            $event = $this->recordDiscussionEvent($game, $nextPlayer, $message, $result, $addressedId);
            broadcast(new PlayerActed($game->id, $event->toData()));

            $speechCounts[$nextPlayer->id]++;
            $statementsMade++;
            $lastSpeakerId = $nextPlayer->id;
            $lastResponseWasQueued = (bool) $addressedByName;

            if ($addressedId) {
                $responseQueue->push(['player_id' => $addressedId, 'from_name' => $nextPlayer->name]);
            }

            $this->waitForAudio();
        }

        $game->phase->transitionTo(DayVoting::class);
        $this->broadcastPhaseChange($game);
    }

    /**
     * Record a discussion event in the database, with optional TTS audio.
     */
    protected function recordDiscussionEvent(Game $game, Player $player, string $message, mixed $result, ?string $addressedId): GameEvent
    {
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'discussion',
            'actor_player_id' => $player->id,
            'target_player_id' => $addressedId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'message' => $message,
                'addressed_player_id' => $addressedId,
            ],
            'is_public' => true,
        ]);

        $this->generateAndAttachAudio($event, $player, $message);

        return $event;
    }

    /**
     * Duration of the last generated audio clip (seconds), or 0.
     */
    protected float $lastAudioDuration = 0;

    /**
     * Total seconds to wait before running the next phase job.
     */
    protected int $pendingDelaySeconds = 0;

    /**
     * Generate TTS audio for an event and attach the URL.
     * Stores the real audio duration for waitForAudio().
     */
    protected function generateAndAttachAudio(GameEvent $event, Player $player, string $text): void
    {
        $this->lastAudioDuration = 0;

        $result = $this->voiceService->generateSpeech($player, $text, $event->id);

        if ($result) {
            $event->update(['audio_url' => $result['url']]);
            $this->lastAudioDuration = $result['duration'];
        }
    }

    /**
     * Accumulate a delay long enough for frontend audio playback.
     */
    protected function waitForAudio(): void
    {
        if ($this->lastAudioDuration > 0) {
            $delay = (int) ceil($this->lastAudioDuration) + 2;
            $this->addDelaySeconds($delay);
        } elseif ($this->voiceService->isAvailable()) {
            $this->addDelaySeconds(4);
        } else {
            $this->addDelaySeconds(1);
        }

        $this->lastAudioDuration = 0;
    }

    /**
     * Resolve a player number (1-based order) from AI output to the real player UUID.
     * Returns null if the player is not alive, is self, or the number is invalid.
     */
    protected function resolvePlayerNumberToId(mixed $number, Game $game): ?string
    {
        $number = (int) $number;

        if ($number <= 0) {
            return null;
        }

        // Player numbers are 1-based (order + 1)
        $player = $game->players()->where('order', $number - 1)->first();

        return $player?->id;
    }

    /**
     * Validate an addressed_player_id from AI output (a player number, not a UUID).
     * Returns null if 0 or not a valid alive player (or is self).
     */
    protected function resolveAddressedPlayerId(mixed $number, Game $game, string $selfId): ?string
    {
        $id = $this->resolvePlayerNumberToId($number, $game);

        if (! $id || $id === $selfId) {
            return null;
        }

        if ($game->alivePlayers()->where('id', $id)->exists()) {
            return $id;
        }

        return null;
    }

    /**
     * Pick a random player weighted by the given weights map.
     *
     * @param  array<int, int>  $weights  Map of player_id => weight
     */
    protected function weightedRandomPick(\Illuminate\Support\Collection $players, array $weights): ?Player
    {
        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            return $players->random();
        }

        $roll = random_int(1, $totalWeight);
        $cumulative = 0;

        foreach ($players as $player) {
            $cumulative += $weights[$player->id] ?? 1;

            if ($roll <= $cumulative) {
                return $player;
            }
        }

        return $players->last();
    }

    /**
     * Nomination → Defense → Trial vote (Ultimate Werewolf style).
     *
     * 1. Each player nominates someone for elimination
     * 2. The most-nominated player goes on trial
     * 3. The accused makes a defense speech
     * 4. Village votes yes (eliminate) or no (spare)
     * 5. Majority required for elimination
     */
    protected function processDayVoting(Game $game): void
    {
        $alivePlayers = $game->alivePlayers()->get();

        // --- Step 1: Nominations ---
        $nominations = [];

        foreach ($alivePlayers as $player) {
            $context = $this->gameContext->buildForPlayer($game, $player);

            $result = VoteAgent::make(
                player: $player,
                game: $game,
                context: $context,
            )->prompt(
                'Nominate a player you want to put on trial for elimination. Consider everything discussed today.',
                provider: $player->provider,
                model: $player->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $player->id);
            $nominations[$player->id] = $targetId;

            $nominationReasoning = $result['public_reasoning'] ?? '';

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'nomination',
                'actor_player_id' => $player->id,
                'target_player_id' => $targetId,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'public_reasoning' => $nominationReasoning,
                ],
                'is_public' => true,
            ]);

            if (! empty($nominationReasoning)) {
                $this->generateAndAttachAudio($event, $player, $nominationReasoning);
            }

            broadcast(new PlayerActed($game->id, $event->toData()));

            if (! empty($nominationReasoning)) {
                $this->waitForAudio();
            }
        }

        // --- Step 2: Determine who goes on trial (most nominations) ---
        $tally = collect($nominations)
            ->countBy()
            ->sortDesc();

        $topCount = $tally->first();
        $topCandidates = $tally->filter(fn (int $count) => $count === $topCount)->keys();

        // On tie, pick randomly among tied candidates
        $accusedId = $topCandidates->count() > 1
            ? $topCandidates->random()
            : $topCandidates->first();

        $accused = Player::find($accusedId);

        if (! $accused) {
            $game->phase->transitionTo(Dusk::class);
            $this->broadcastPhaseChange($game);

            return;
        }

        // Record nomination result
        $nominationTally = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'nomination_result',
            'target_player_id' => $accusedId,
            'data' => [
                'message' => "{$accused->name} has been put on trial with {$topCount} nomination(s).",
                'tally' => $tally->all(),
            ],
            'is_public' => true,
        ]);
        broadcast(new PlayerActed($game->id, $nominationTally->toData()));

        // --- Step 3: Defense speech ---
        $defenseContext = $this->gameContext->buildForPlayer($game, $accused);
        $defenseContext .= "\n\n## YOU ARE ON TRIAL\nThe village has nominated you for elimination. This is your chance to defend yourself. Convince them you are not a werewolf!";

        $defenseResult = DiscussionAgent::make(
            player: $accused,
            game: $game,
            context: $defenseContext,
        )->prompt(
            'You are on trial! Make your defense speech. Convince the village to spare you.',
            provider: $accused->provider,
            model: $accused->model,
        );

        $defenseMessage = $defenseResult['message'] ?? (string) $defenseResult;

        $defenseEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'defense_speech',
            'actor_player_id' => $accusedId,
            'data' => [
                'thinking' => $defenseResult['thinking'] ?? '',
                'message' => $defenseMessage,
            ],
            'is_public' => true,
        ]);

        $this->generateAndAttachAudio($defenseEvent, $accused, $defenseMessage);
        broadcast(new PlayerActed($game->id, $defenseEvent->toData()));

        $this->waitForAudio();

        // --- Step 4: Trial vote (yes = eliminate, no = spare) ---
        $yesVotes = 0;
        $noVotes = 0;
        $voters = $alivePlayers->filter(fn (Player $p) => $p->id !== $accusedId);

        $accusedNumber = $accused->order + 1;

        foreach ($voters as $voter) {
            $voteContext = $this->gameContext->buildForPlayer($game, $voter);
            $voteContext .= "\n\n## TRIAL VOTE\n{$accused->name} [{$accusedNumber}] is on trial. You heard their defense. Vote YES to eliminate them or NO to spare them.\nSet target_id to {$accusedNumber} if you vote YES (eliminate), or 0 if you vote NO (spare).";

            $voteResult = VoteAgent::make(
                player: $voter,
                game: $game,
                context: $voteContext,
            )->prompt(
                "Vote on {$accused->name}'s fate. target_id={$accusedNumber} for YES (eliminate), target_id=0 for NO (spare).",
                provider: $voter->provider,
                model: $voter->model,
            );

            $votedYes = ((int) ($voteResult['target_id'] ?? 0)) === $accusedNumber;

            if ($votedYes) {
                $yesVotes++;
            } else {
                $noVotes++;
            }

            $reasoning = $voteResult['public_reasoning'] ?? '';

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'vote',
                'actor_player_id' => $voter->id,
                'target_player_id' => $votedYes ? $accusedId : null,
                'data' => [
                    'thinking' => $voteResult['thinking'] ?? '',
                    'public_reasoning' => $reasoning,
                    'vote' => $votedYes ? 'yes' : 'no',
                ],
                'is_public' => true,
            ]);

            // Generate audio for the vote reasoning
            if (! empty($reasoning)) {
                $this->generateAndAttachAudio($event, $voter, $reasoning);
            }

            broadcast(new PlayerActed($game->id, $event->toData()));

            if (! empty($reasoning)) {
                $this->waitForAudio();
            }
        }

        // Record trial result
        $voteTallyEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'vote_tally',
            'target_player_id' => $accusedId,
            'data' => [
                'message' => "Trial vote for {$accused->name}: {$yesVotes} yes, {$noVotes} no.",
                'yes' => $yesVotes,
                'no' => $noVotes,
            ],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $voteTallyEvent->toData()));
        $this->addDelaySeconds(3);

        // Majority required to eliminate
        if ($yesVotes > $noVotes) {
            $accused->update(['is_alive' => false]);

            $eliminationEvent = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'elimination',
                'target_player_id' => $accusedId,
                'data' => [
                    'message' => "{$accused->name} has been eliminated by the village. Their role is confirmed: {$accused->role->value}.",
                    'role_revealed' => $accused->role->value,
                    'votes_yes' => $yesVotes,
                    'votes_no' => $noVotes,
                ],
                'is_public' => true,
            ]);

            broadcast(new PlayerEliminated(
                $game->id,
                $eliminationEvent->toData(),
                $accusedId,
                $accused->role->value,
            ));

            $this->addDelaySeconds(3);

            // Dying speech + Hunter revenge for the eliminated player
            $this->giveDyingSpeech($game, $accused);
            $this->handleHunterRevenge($game, $accused);

            // Check Tanner win (and other win conditions) immediately
            if ($this->checkWinCondition($game, eliminatedByVillage: $accused)) {
                return;
            }
        } else {
            $noElimEvent = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'no_elimination',
                'target_player_id' => $accusedId,
                'data' => ['message' => "{$accused->name} has been spared by the village ({$yesVotes} yes, {$noVotes} no)."],
                'is_public' => true,
            ]);

            broadcast(new PlayerActed($game->id, $noElimEvent->toData()));
            $this->addDelaySeconds(3);
        }

        $game->phase->transitionTo(Dusk::class);
        $this->broadcastPhaseChange($game);
    }

    // =========================================================================
    // Dusk — wrap up the day, start next round
    // =========================================================================

    protected function processDusk(Game $game): void
    {
        // Check win condition
        if ($this->checkWinCondition($game)) {
            return;
        }

        // Start next round
        $game->update(['round' => $game->round + 1]);
        $this->transitionToPhase($game, NightWerewolf::class);
    }

    // =========================================================================
    // Dying speech
    // =========================================================================

    /**
     * Give an eliminated player their last words.
     */
    public function giveDyingSpeech(Game $game, Player $player): void
    {
        try {
            $context = $this->gameContext->buildForPlayer($game, $player);
            $context .= "\n\n## YOUR FINAL WORDS\nYou have been eliminated from the game. Your role was {$player->role->value}. You may now give your dying speech — your last chance to influence the game.";

            $result = DiscussionAgent::make(
                player: $player,
                game: $game,
                context: $context,
            )->prompt(
                'You have been eliminated. Give your dying speech — your last words to the village.',
                provider: $player->provider,
                model: $player->model,
            );

            $message = $result['message'] ?? (string) $result;

            $event = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'dying_speech',
                'actor_player_id' => $player->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'message' => $message,
                ],
                'is_public' => true,
            ]);

            $this->generateAndAttachAudio($event, $player, $message);

            broadcast(new PlayerActed($game->id, $event->toData()));

            $this->waitForAudio();
        } catch (\Throwable $e) {
            Log::warning('Failed to get dying speech', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Hunter revenge kill
    // =========================================================================

    /**
     * If the eliminated player is the Hunter, they take someone down with them.
     */
    protected function handleHunterRevenge(Game $game, Player $deadPlayer): void
    {
        if ($deadPlayer->role !== GameRole::Hunter) {
            return;
        }

        try {
            $context = $this->gameContext->buildForPlayer($game, $deadPlayer);
            $context .= "\n\n## HUNTER'S REVENGE\nYou have been eliminated, but as the Hunter, you get to take one player down with you! Choose wisely — target who you believe is a werewolf.";

            $result = NightActionAgent::make(
                player: $deadPlayer,
                game: $game,
                context: $context,
                actionPrompt: 'You are the Hunter. Choose one alive player to shoot with your dying action. Pick who you believe is a werewolf.',
            )->prompt(
                'Choose a player to take down with you.',
                provider: $deadPlayer->provider,
                model: $deadPlayer->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $deadPlayer->id);
            $target = $targetId ? Player::find($targetId) : null;

            if ($target && $target->is_alive) {
                $target->update(['is_alive' => false]);

                $hunterMessage = "{$deadPlayer->name} was the Hunter and shoots {$target->name} with their dying breath! {$target->name} was a {$target->role->value}.";

                $event = $game->events()->create([
                    'round' => $game->round,
                    'phase' => $game->phase->getValue(),
                    'type' => 'hunter_shot',
                    'actor_player_id' => $deadPlayer->id,
                    'target_player_id' => $target->id,
                    'data' => [
                        'thinking' => $result['thinking'] ?? '',
                        'public_reasoning' => $result['public_reasoning'] ?? '',
                        'message' => $hunterMessage,
                        'role_revealed' => $target->role->value,
                    ],
                    'is_public' => true,
                ]);

                $this->generateAndAttachAudio($event, $deadPlayer, $hunterMessage);

                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $target->id,
                    $target->role->value,
                ));

                $this->waitForAudio();

                // The shot player also gets a dying speech
                $this->giveDyingSpeech($game, $target);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to process Hunter revenge', [
                'player_id' => $deadPlayer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Win condition
    // =========================================================================

    /**
     * Check win conditions including Tanner special win.
     *
     * @param  Player|null  $eliminatedByVillage  If set, the player just voted out (check Tanner win)
     */
    public function checkWinCondition(Game $game, ?Player $eliminatedByVillage = null): bool
    {
        $game->refresh();

        // Tanner win: if the Tanner was eliminated by village vote, Tanner wins (solo)
        if ($eliminatedByVillage && $eliminatedByVillage->role === GameRole::Tanner) {
            $game->update(['winner' => GameTeam::Neutral, 'phase_step' => 0]);
            $game->phase->transitionTo(GameOver::class);
            $game->status->transitionTo(Finished::class);

            $game->events()->create([
                'round' => $game->round,
                'phase' => 'game_over',
                'type' => 'game_end',
                'data' => [
                    'winner' => GameTeam::Neutral->value,
                    'message' => "{$eliminatedByVillage->name} was the Tanner and WANTED to be eliminated! The Tanner wins!",
                ],
                'is_public' => true,
            ]);

            broadcast(new GameEnded($game->id, GameTeam::Neutral->value, "{$eliminatedByVillage->name} was the Tanner and wins!"));
            $this->broadcastPhaseChange($game);

            return true;
        }

        $alive = $game->alivePlayers()->get();

        $werewolvesAlive = $alive->filter(
            fn (Player $p) => $p->role === GameRole::Werewolf
        )->count();

        // Count non-werewolf, non-tanner alive players for balance check
        $villagersAlive = $alive->filter(
            fn (Player $p) => $p->role !== GameRole::Werewolf
        )->count();

        $winner = null;
        $message = '';

        if ($werewolvesAlive === 0) {
            $winner = GameTeam::Village;
            $message = 'All werewolves have been eliminated! The village wins!';
        } elseif ($werewolvesAlive >= $villagersAlive) {
            $winner = GameTeam::Werewolves;
            $message = 'The werewolves have taken over the village! Werewolves win!';
        }

        if ($winner) {
            $game->update(['winner' => $winner, 'phase_step' => 0]);
            $game->phase->transitionTo(GameOver::class);
            $game->status->transitionTo(Finished::class);

            $game->events()->create([
                'round' => $game->round,
                'phase' => 'game_over',
                'type' => 'game_end',
                'data' => [
                    'winner' => $winner->value,
                    'message' => $message,
                ],
                'is_public' => true,
            ]);

            broadcast(new GameEnded($game->id, $winner->value, $message));
            $this->broadcastPhaseChange($game);

            return true;
        }

        return false;
    }

    // =========================================================================
    // Role distribution (scales with player count)
    // =========================================================================

    /**
     * Distribute roles based on player count (Ultimate Werewolf scaling).
     * - 5-6 players: 1 werewolf
     * - 7-11 players: 2 werewolves
     * - 12+ players: 3 werewolves
     * Always: 1 Seer, 1 Bodyguard, 1 Hunter.
     * Tanner added at 7+ players.
     * Rest are Villagers.
     *
     * @return GameRole[]
     */
    protected function distributeRoles(int $playerCount): array
    {
        $werewolfCount = match (true) {
            $playerCount <= 6 => 1,
            $playerCount <= 11 => 2,
            default => 3,
        };

        $roles = [];

        for ($i = 0; $i < $werewolfCount; $i++) {
            $roles[] = GameRole::Werewolf;
        }

        $roles[] = GameRole::Seer;
        $roles[] = GameRole::Bodyguard;
        $roles[] = GameRole::Hunter;

        // Tanner at 7+ players (adds a wild card)
        if ($playerCount >= 7) {
            $roles[] = GameRole::Tanner;
        }

        // Fill remaining with villagers
        $villagersNeeded = $playerCount - count($roles);
        for ($i = 0; $i < $villagersNeeded; $i++) {
            $roles[] = GameRole::Villager;
        }

        return $roles;
    }

    /**
     * Build a human-readable role distribution map (e.g. {"Werewolf": 2, "Seer": 1, ...}).
     *
     * @param  GameRole[]  $roles
     * @return array<string, int>
     */
    protected function buildRoleDistribution(array $roles): array
    {
        $distribution = [];

        foreach ($roles as $role) {
            $name = $this->roleRegistry->get($role)->name();
            $distribution[$name] = ($distribution[$name] ?? 0) + 1;
        }

        return $distribution;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve a target_id (player number) from AI output to a valid alive player UUID.
     * Falls back to a random alive player if the number is invalid.
     */
    protected function resolveTargetId(mixed $playerNumber, Game $game, ?string $excludePlayerId = null): ?string
    {
        $targetId = $this->resolvePlayerNumberToId($playerNumber, $game);

        if ($targetId) {
            // Verify the target is a valid alive player in this game
            $query = $game->alivePlayers()->where('id', $targetId);

            if ($excludePlayerId) {
                $query->where('id', '!=', $excludePlayerId);
            }

            if ($query->exists()) {
                return $targetId;
            }
        }

        // Fall back to a random alive player (excluding self if needed)
        $fallbackQuery = $game->alivePlayers();
        if ($excludePlayerId) {
            $fallbackQuery->where('id', '!=', $excludePlayerId);
        }

        return $fallbackQuery->inRandomOrder()->first()?->id;
    }

    protected function broadcastPhaseChange(Game $game, bool $narrate = true): void
    {
        $game->refresh();
        $phase = $game->phase;

        $narrationText = null;
        $narrationAudioUrl = null;

        // Generate AI narration for key phases (can be deferred for phases
        // like dawn where we need to resolve events first)
        if ($narrate) {
            $narration = $this->voiceService->narrate($game, $phase);

            if ($narration) {
                $narrationText = $narration['text'];
                $narrationAudioUrl = $narration['url'];

                // Record narration as a game event (visible in log)
                $game->events()->create([
                    'round' => $game->round,
                    'phase' => $phase->getValue(),
                    'type' => 'narration',
                    'data' => [
                        'message' => $narrationText,
                    ],
                    'audio_url' => $narrationAudioUrl,
                    'is_public' => true,
                ]);

                $this->lastAudioDuration = $narration['duration'];
            }
        }

        broadcast(new GamePhaseChanged(
            $game->id,
            $phase->getValue(),
            $game->round,
            $phase->description(),
            narration: $narrationText,
            narration_audio_url: $narrationAudioUrl,
        ));

        // Wait for narrator audio to finish before proceeding
        if ($narrationText) {
            $this->waitForAudio();
        }
    }

    /**
     * Generate narration for the current phase and broadcast it separately.
     * Used when narration must be deferred (e.g. dawn — after night resolution).
     */
    protected function generateAndBroadcastNarration(Game $game): void
    {
        $game->refresh();
        $phase = $game->phase;

        $narration = $this->voiceService->narrate($game, $phase);

        if (! $narration) {
            return;
        }

        $game->events()->create([
            'round' => $game->round,
            'phase' => $phase->getValue(),
            'type' => 'narration',
            'data' => [
                'message' => $narration['text'],
            ],
            'audio_url' => $narration['url'],
            'is_public' => true,
        ]);

        // Broadcast an updated phase change with the narration attached
        broadcast(new GamePhaseChanged(
            $game->id,
            $phase->getValue(),
            $game->round,
            $phase->description(),
            narration: $narration['text'],
            narration_audio_url: $narration['url'],
        ));

        $this->lastAudioDuration = $narration['duration'];
        $this->waitForAudio();
    }

    protected function advancePhaseStep(Game $game): void
    {
        $game->update(['phase_step' => $game->phase_step + 1]);
    }

    public function transitionToPhase(Game $game, string $phaseClass, bool $narrate = true): void
    {
        $game->phase->transitionTo($phaseClass);
        $game->update(['phase_step' => 0]);
        $this->broadcastPhaseChange($game, narrate: $narrate);
    }

    protected function processWerewolfProposal(Game $game, Player $werewolf): void
    {
        $context = $this->gameContext->buildForPlayer($game, $werewolf);
        $existingProposals = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'werewolf_kill')
            ->orderBy('id')
            ->get();

        if ($existingProposals->isNotEmpty()) {
            $context .= "\n\n## Werewolf Pack Discussion\n";
            $context .= "Your fellow wolves have already proposed targets:\n";
            foreach ($existingProposals as $proposal) {
                $wolfName = $proposal->actor?->name ?? 'Unknown wolf';
                $targetName = $proposal->target?->name ?? 'unknown';
                $reasoning = $proposal->data['public_reasoning'] ?? '';
                $context .= "- {$wolfName} wants to kill {$targetName}: \"{$reasoning}\"\n";
            }
            $context .= "\nConsider agreeing with your pack for a coordinated attack, unless you have a strong reason to disagree.";
        }

        $role = $this->roleRegistry->get($werewolf->role);
        $result = NightActionAgent::make(
            player: $werewolf,
            game: $game,
            context: $context,
            actionPrompt: $role->nightActionPrompt(),
        )->prompt(
            'Choose your target for tonight.',
            provider: $werewolf->provider,
            model: $werewolf->model,
        );

        $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $werewolf->id);
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'werewolf_kill',
            'actor_player_id' => $werewolf->id,
            'target_player_id' => $targetId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $result['public_reasoning'] ?? '',
            ],
            'is_public' => false,
        ]);

        broadcast(new PlayerActed($game->id, $event->toData()));
    }

    protected function resolveWerewolfTarget(Game $game): void
    {
        $finalTarget = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'werewolf_kill')
            ->pluck('target_player_id')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'werewolf_kill')
            ->update(['target_player_id' => $finalTarget]);
    }

    /**
     * @param  Collection<int,Player>  $alivePlayers
     * @return array{opening_order: array<int,string>, total_budget:int}
     */
    public function getOrCreateDiscussionPlan(Game $game, Collection $alivePlayers): array
    {
        $planEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'discussion_plan')
            ->latest('id')
            ->first();

        if ($planEvent) {
            return $planEvent->data;
        }

        $openingOrder = $alivePlayers->shuffle()->pluck('id')->values()->all();
        $totalBudget = $alivePlayers->count() * 2;

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'discussion_plan',
            'data' => [
                'opening_order' => $openingOrder,
                'total_budget' => $totalBudget,
            ],
            'is_public' => false,
        ]);

        return $event->data;
    }

    public function createDiscussionMessage(Game $game, Player $speaker, string $prompt): void
    {
        $context = $this->gameContext->buildForPlayer($game, $speaker);
        $result = DiscussionAgent::make(
            player: $speaker,
            game: $game,
            context: $context,
        )->prompt(
            $prompt,
            provider: $speaker->provider,
            model: $speaker->model,
        );

        $wantsToSpeak = $result['wants_to_speak'] ?? true;
        if (! $wantsToSpeak) {
            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'discussion_pass',
                'actor_player_id' => $speaker->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                ],
                'is_public' => false,
            ]);

            return;
        }

        $message = $result['message'] ?? (string) $result;
        $addressedId = $this->resolveAddressedPlayerId($result['addressed_player_id'] ?? 0, $game, $speaker->id);
        $event = $this->recordDiscussionEvent($game, $speaker, $message, $result, $addressedId);
        broadcast(new PlayerActed($game->id, $event->toData()));
        $this->waitForAudio();
    }

    public function createNomination(Game $game, Player $player): void
    {
        $context = $this->gameContext->buildForPlayer($game, $player);
        $result = VoteAgent::make(
            player: $player,
            game: $game,
            context: $context,
        )->prompt(
            'Nominate a player you want to put on trial for elimination. Consider everything discussed today.',
            provider: $player->provider,
            model: $player->model,
        );

        $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $player->id);
        $reasoning = $result['public_reasoning'] ?? '';
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'nomination',
            'actor_player_id' => $player->id,
            'target_player_id' => $targetId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $reasoning,
            ],
            'is_public' => true,
        ]);

        if (! empty($reasoning)) {
            $this->generateAndAttachAudio($event, $player, $reasoning);
        }

        broadcast(new PlayerActed($game->id, $event->toData()));

        if (! empty($reasoning)) {
            $this->waitForAudio();
        }
    }

    public function createNominationResult(Game $game, Collection $alivePlayers): ?Player
    {
        $tally = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'nomination')
            ->pluck('target_player_id')
            ->countBy()
            ->sortDesc();

        $topCount = $tally->first();
        if ($topCount === null) {
            return null;
        }

        $topCandidates = $tally->filter(fn (int $count) => $count === $topCount)->keys();
        $accusedId = $topCandidates->count() > 1
            ? $topCandidates->random()
            : $topCandidates->first();

        $accused = $alivePlayers->firstWhere('id', $accusedId);
        if (! $accused) {
            return null;
        }

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'nomination_result',
            'target_player_id' => $accused->id,
            'data' => [
                'message' => "{$accused->name} has been put on trial with {$topCount} nomination(s).",
                'tally' => $tally->all(),
            ],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $event->toData()));

        return $accused;
    }

    public function createDefenseSpeech(Game $game, Player $accused): void
    {
        $defenseContext = $this->gameContext->buildForPlayer($game, $accused);
        $defenseContext .= "\n\n## YOU ARE ON TRIAL\nThe village has nominated you for elimination. This is your chance to defend yourself. Convince them you are not a werewolf!";

        $defenseResult = DiscussionAgent::make(
            player: $accused,
            game: $game,
            context: $defenseContext,
        )->prompt(
            'You are on trial! Make your defense speech. Convince the village to spare you.',
            provider: $accused->provider,
            model: $accused->model,
        );

        $defenseMessage = $defenseResult['message'] ?? (string) $defenseResult;
        $defenseEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'defense_speech',
            'actor_player_id' => $accused->id,
            'data' => [
                'thinking' => $defenseResult['thinking'] ?? '',
                'message' => $defenseMessage,
            ],
            'is_public' => true,
        ]);

        $this->generateAndAttachAudio($defenseEvent, $accused, $defenseMessage);
        broadcast(new PlayerActed($game->id, $defenseEvent->toData()));
        $this->waitForAudio();
    }

    public function createTrialVote(Game $game, Player $voter, Player $accused): void
    {
        $accusedNumber = $accused->order + 1;
        $voteContext = $this->gameContext->buildForPlayer($game, $voter);
        $voteContext .= "\n\n## TRIAL VOTE\n{$accused->name} [{$accusedNumber}] is on trial. You heard their defense. Vote YES to eliminate them or NO to spare them.\nSet target_id to {$accusedNumber} if you vote YES (eliminate), or 0 if you vote NO (spare).";

        $voteResult = VoteAgent::make(
            player: $voter,
            game: $game,
            context: $voteContext,
        )->prompt(
            "Vote on {$accused->name}'s fate. target_id={$accusedNumber} for YES (eliminate), target_id=0 for NO (spare).",
            provider: $voter->provider,
            model: $voter->model,
        );

        $votedYes = ((int) ($voteResult['target_id'] ?? 0)) === $accusedNumber;
        $reasoning = $voteResult['public_reasoning'] ?? '';
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'vote',
            'actor_player_id' => $voter->id,
            'target_player_id' => $votedYes ? $accused->id : null,
            'data' => [
                'thinking' => $voteResult['thinking'] ?? '',
                'public_reasoning' => $reasoning,
                'vote' => $votedYes ? 'yes' : 'no',
            ],
            'is_public' => true,
        ]);

        if (! empty($reasoning)) {
            $this->generateAndAttachAudio($event, $voter, $reasoning);
        }

        broadcast(new PlayerActed($game->id, $event->toData()));

        if (! empty($reasoning)) {
            $this->waitForAudio();
        }
    }

    /**
     * @return array{eliminated_id: string|null}
     */
    public function createTrialOutcome(Game $game, Player $accused): array
    {
        $yesVotes = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote')
            ->where('target_player_id', $accused->id)
            ->count();

        $totalVotes = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote')
            ->count();
        $noVotes = max(0, $totalVotes - $yesVotes);

        $voteTallyEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'vote_tally',
            'target_player_id' => $accused->id,
            'data' => [
                'message' => "Trial vote for {$accused->name}: {$yesVotes} yes, {$noVotes} no.",
                'yes' => $yesVotes,
                'no' => $noVotes,
            ],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $voteTallyEvent->toData()));
        $this->addDelaySeconds(3);

        if ($yesVotes > $noVotes) {
            $accused->update(['is_alive' => false]);

            $eliminationEvent = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'elimination',
                'target_player_id' => $accused->id,
                'data' => [
                    'message' => "{$accused->name} has been eliminated by the village. Their role is confirmed: {$accused->role->value}.",
                    'role_revealed' => $accused->role->value,
                    'votes_yes' => $yesVotes,
                    'votes_no' => $noVotes,
                ],
                'is_public' => true,
            ]);

            broadcast(new PlayerEliminated(
                $game->id,
                $eliminationEvent->toData(),
                $accused->id,
                $accused->role->value,
            ));
            $this->addDelaySeconds(3);

            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'vote_outcome',
                'data' => ['eliminated_id' => $accused->id],
                'is_public' => false,
            ]);

            return ['eliminated_id' => $accused->id];
        }

        $noElimEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'no_elimination',
            'target_player_id' => $accused->id,
            'data' => ['message' => "{$accused->name} has been spared by the village ({$yesVotes} yes, {$noVotes} no)."],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $noElimEvent->toData()));
        $this->addDelaySeconds(3);

        $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'vote_outcome',
            'data' => ['eliminated_id' => null],
            'is_public' => false,
        ]);

        return ['eliminated_id' => null];
    }

    /**
     * @return array{killed_player_id: string|null}
     */
    protected function getOrCreateDawnResolution(Game $game): array
    {
        $resolutionEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'dawn_resolution')
            ->latest('id')
            ->first();

        if ($resolutionEvent) {
            return $resolutionEvent->data;
        }

        $result = $this->nightResolver->resolve($game);
        $resolution = [
            'killed_player_id' => $result['killed']?->id,
        ];

        $created = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'dawn_resolution',
            'data' => $resolution,
            'is_public' => false,
        ]);

        return $created->data;
    }

    public function processHunterRevengeShot(Game $game, Player $deadPlayer): void
    {
        if ($deadPlayer->role !== GameRole::Hunter) {
            return;
        }

        try {
            $context = $this->gameContext->buildForPlayer($game, $deadPlayer);
            $context .= "\n\n## HUNTER'S REVENGE\nYou have been eliminated, but as the Hunter, you get to take one player down with you! Choose wisely — target who you believe is a werewolf.";

            $result = NightActionAgent::make(
                player: $deadPlayer,
                game: $game,
                context: $context,
                actionPrompt: 'You are the Hunter. Choose one alive player to shoot with your dying action. Pick who you believe is a werewolf.',
            )->prompt(
                'Choose a player to take down with you.',
                provider: $deadPlayer->provider,
                model: $deadPlayer->model,
            );

            $targetId = $this->resolveTargetId($result['target_id'], $game, excludePlayerId: $deadPlayer->id);
            $target = $targetId ? Player::find($targetId) : null;

            if ($target && $target->is_alive) {
                $target->update(['is_alive' => false]);

                $hunterMessage = "{$deadPlayer->name} was the Hunter and shoots {$target->name} with their dying breath! {$target->name} was a {$target->role->value}.";
                $event = $game->events()->create([
                    'round' => $game->round,
                    'phase' => $game->phase->getValue(),
                    'type' => 'hunter_shot',
                    'actor_player_id' => $deadPlayer->id,
                    'target_player_id' => $target->id,
                    'data' => [
                        'thinking' => $result['thinking'] ?? '',
                        'public_reasoning' => $result['public_reasoning'] ?? '',
                        'message' => $hunterMessage,
                        'role_revealed' => $target->role->value,
                    ],
                    'is_public' => true,
                ]);

                $this->generateAndAttachAudio($event, $deadPlayer, $hunterMessage);
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $target->id,
                    $target->role->value,
                ));
                $this->waitForAudio();
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to process Hunter revenge', [
                'player_id' => $deadPlayer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function addDelaySeconds(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $this->pendingDelaySeconds += $seconds;
    }
}
