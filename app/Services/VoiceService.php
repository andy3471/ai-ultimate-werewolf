<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\States\GamePhase\GamePhaseState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Audio;

class VoiceService
{
    /**
     * OpenAI TTS voices — 6 distinct voices to cycle through.
     */
    protected const OPENAI_VOICES = [
        'alloy',   // neutral
        'echo',    // male
        'fable',   // British
        'onyx',    // deep male
        'nova',    // female
        'shimmer', // soft female
    ];

    /**
     * ElevenLabs preset voice IDs (popular defaults).
     */
    protected const ELEVENLABS_VOICES = [
        'JBFqnCBsd6RMkjVDRZzb', // George
        'TX3LPaxmHKxFdv7VOQHJ', // Liam
        'XB0fDUnXU5powFXDhCwa', // Charlotte
        'Xb7hH8MSUJpSbSDYk0k2', // Alice
        'bIHbv24MWmeRgasZH58o', // Will
        'cgSgspJ2msm6clMCkdW9', // Jessica
        'cjVigY5qzO86Huf0OWal', // Eric
        'iP95p4xoKVk53GoZ742B', // Chris
        'nPczCjzI2devNBz1zQrb', // Brian
        'onwK4e9ZLuTAKqWW03F9', // Daniel
        'pFZP5JQG7iQjIQuC4Bku', // Lily
        'pqHfZKP75CvOlQylNhV4', // Bill
    ];

    /**
     * Determine which TTS provider to use based on available API keys.
     * Returns 'eleven', 'openai', or null if neither is configured.
     */
    public function getProvider(): ?string
    {
        $elevenKey = config('ai.providers.eleven.key');
        $openaiKey = config('ai.providers.openai.key');

        if (! empty($elevenKey)) {
            return 'eleven';
        }

        if (! empty($openaiKey)) {
            return 'openai';
        }

        return null;
    }

    /**
     * Check if TTS is available (at least one provider has an API key).
     */
    public function isAvailable(): bool
    {
        return $this->getProvider() !== null;
    }

    /**
     * Assign a consistent voice to each player in the game.
     * The voice is derived from the player's name so the same name
     * always gets the same voice across different games.
     * When two players hash to the same voice, the second one gets
     * the next available voice to avoid duplicates within a game.
     */
    public function assignVoices(Game $game): void
    {
        $provider = $this->getProvider();

        if (! $provider) {
            return;
        }

        $voices = $provider === 'eleven'
            ? self::ELEVENLABS_VOICES
            : self::OPENAI_VOICES;

        $players = $game->players()->orderBy('order')->get();
        $usedVoices = [];

        foreach ($players as $player) {
            // Deterministic: hash the player name to pick a voice index
            $hash = crc32($player->name);
            $preferredIndex = abs($hash) % count($voices);

            // Find the preferred voice, or the next unused one if it's taken
            $voiceId = null;
            for ($i = 0; $i < count($voices); $i++) {
                $candidateIndex = ($preferredIndex + $i) % count($voices);
                if (! in_array($voices[$candidateIndex], $usedVoices, true)) {
                    $voiceId = $voices[$candidateIndex];
                    break;
                }
            }

            // Fallback: if all voices are taken (more players than voices), reuse preferred
            $voiceId ??= $voices[$preferredIndex];

            $usedVoices[] = $voiceId;
            $player->update(['voice' => "{$provider}:{$voiceId}"]);
        }
    }

    /**
     * Generate speech audio for a player's message.
     * Returns [url, durationSeconds] or null on failure.
     *
     * @return array{url: string, duration: float}|null
     */
    public function generateSpeech(Player $player, string $text, int $eventId): ?array
    {
        if (! $this->isAvailable() || empty($player->voice) || empty(trim($text))) {
            return null;
        }

        try {
            [$provider, $voiceId] = explode(':', $player->voice, 2);

            $response = Audio::of($text)
                ->voice($voiceId)
                ->generate(provider: $provider);

            // Store the audio file using the SDK's built-in store method
            $gameId = $player->game_id;
            $path = $response->storePubliclyAs(
                "audio/game-{$gameId}",
                "{$eventId}.mp3",
                'public',
            );

            if (! $path) {
                return null;
            }

            $url = Storage::disk('public')->url($path);
            $absolutePath = Storage::disk('public')->path($path);
            $duration = $this->getAudioDuration($absolutePath);

            return ['url' => $url, 'duration' => $duration];
        } catch (\Throwable $e) {
            Log::warning('TTS generation failed', [
                'player_id' => $player->id,
                'voice' => $player->voice,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the duration of an audio file in seconds using ffprobe.
     */
    protected function getAudioDuration(string $filePath): float
    {
        try {
            $output = shell_exec(sprintf(
                'ffprobe -v quiet -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
                escapeshellarg($filePath),
            ));

            $duration = (float) trim((string) $output);

            return $duration > 0 ? $duration : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    // =========================================================================
    // Narrator
    // =========================================================================

    /**
     * Narrator voice — a deep authoritative voice distinct from players.
     * OpenAI "onyx" (deep male) or the first ElevenLabs voice.
     */
    protected const NARRATOR_VOICE_OPENAI = 'onyx';

    protected const NARRATOR_VOICE_ELEVEN = 'nPczCjzI2devNBz1zQrb'; // Brian

    /**
     * Phases that should receive narration.
     */
    protected const NARRATED_PHASES = [
        'night_werewolf',
        'dawn',
        'day_discussion',
        'day_voting',
        'game_over',
    ];

    /**
     * Check if a phase should be narrated.
     */
    public function shouldNarrate(string $phase): bool
    {
        return $this->isAvailable() && in_array($phase, self::NARRATED_PHASES, true);
    }

    /**
     * Generate a narrator line for a phase transition using AI text, then voice it via TTS.
     *
     * @return array{text: string, url: string, duration: float}|null
     */
    public function narrate(Game $game, GamePhaseState $phase): ?array
    {
        if (! $this->shouldNarrate($phase->getValue())) {
            return null;
        }

        try {
            // Generate creative narration text via AI
            $narrationText = $this->generateNarrationText($game, $phase);

            if (empty($narrationText)) {
                return null;
            }

            // Generate TTS for the narration
            $provider = $this->getProvider();
            $voiceId = $provider === 'eleven'
                ? self::NARRATOR_VOICE_ELEVEN
                : self::NARRATOR_VOICE_OPENAI;

            $response = Audio::of($narrationText)
                ->voice($voiceId)
                ->instructions('Speak in a deep, dramatic narrator voice. Atmospheric and commanding.')
                ->generate(provider: $provider);

            $filename = "narrator-{$game->round}-{$phase->getValue()}";
            $path = $response->storePubliclyAs(
                "audio/game-{$game->id}",
                "{$filename}.mp3",
                'public',
            );

            if (! $path) {
                return null;
            }

            $url = Storage::disk('public')->url($path);
            $absolutePath = Storage::disk('public')->path($path);
            $duration = $this->getAudioDuration($absolutePath);

            return [
                'text' => $narrationText,
                'url' => $url,
                'duration' => $duration,
            ];
        } catch (\Throwable $e) {
            Log::warning('Narrator generation failed', [
                'game_id' => $game->id,
                'phase' => $phase->getValue(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Use AI to generate a creative narrator line for the phase transition.
     */
    protected function generateNarrationText(Game $game, GamePhaseState $phase): string
    {
        $aliveCount = $game->alivePlayers()->count();
        $round = $game->round;
        $phaseValue = $phase->getValue();

        $context = $this->buildNarrationContext($game, $phaseValue, $round, $aliveCount);

        $agent = new AnonymousAgent(
            instructions: <<<'PROMPT'
You are the narrator of a Werewolf game. Your job is to clearly announce what is happening, then add a brief atmospheric touch.

Rules:
- ALWAYS start by clearly stating the phase: "Night falls.", "Dawn breaks.", "The village gathers for discussion.", "It's time to vote.", "The game is over."
- ALWAYS name specific players when given (who died, who was eliminated, who won)
- State the facts FIRST, then add ONE short atmospheric sentence if you like
- Keep it to 2-3 sentences total
- Do NOT reveal hidden roles unless the context explicitly says the role was revealed
- Do NOT be overly poetic — clarity comes first
PROMPT,
            messages: [],
            tools: [],
        );

        $response = $agent->prompt(
            $context,
            provider: 'openai',
            model: 'gpt-4.1-nano',
        );

        return trim((string) $response);
    }

    /**
     * Build rich context for the narrator based on what just happened.
     */
    protected function buildNarrationContext(Game $game, string $phase, int $round, int $aliveCount): string
    {
        $recentEvents = $game->events()
            ->where('is_public', true)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->reverse();

        $parts = [];

        switch ($phase) {
            case 'night_werewolf':
                if ($round === 1) {
                    $playerNames = $game->players()->pluck('name')->implode(', ');
                    $parts[] = "Announce: It is Night 1. The players in this game are: {$playerNames}.";
                    $parts[] = "The werewolves open their eyes for the first time to see who their fellow wolves are.";
                } else {
                    $parts[] = "Announce: Night {$round} begins. {$aliveCount} players remain.";
                    // What happened during the day
                    $dayEvent = $recentEvents->firstWhere('type', 'elimination');
                    if ($dayEvent) {
                        $parts[] = "Earlier today: {$dayEvent->data['message']}";
                    }
                    $noElim = $recentEvents->firstWhere('type', 'no_elimination');
                    if ($noElim) {
                        $parts[] = "Earlier today: {$noElim->data['message']}";
                    }
                    $parts[] = 'The werewolves must now choose their next victim.';
                }
                break;

            case 'dawn':
                $parts[] = "Announce: Dawn breaks on Day {$round}.";
                $deathEvent = $recentEvents->firstWhere('type', 'death');
                $saveEvent = $recentEvents->firstWhere('type', 'bodyguard_save');
                $noDeathEvent = $recentEvents->firstWhere('type', 'no_death');

                if ($deathEvent) {
                    $targetName = $deathEvent->target?->name ?? 'someone';
                    $role = $deathEvent->data['role_revealed'] ?? null;
                    $parts[] = "{$targetName} was killed by the werewolves during the night.";
                    if ($role) {
                        $parts[] = "Their role is revealed: they were the {$role}.";
                    }
                    $parts[] = "{$aliveCount} players remain.";
                } elseif ($saveEvent) {
                    $parts[] = 'No one was killed last night — the bodyguard successfully protected the werewolves\' target!';
                } elseif ($noDeathEvent) {
                    $parts[] = 'No one was killed during the night.';
                }

                $hunterEvent = $recentEvents->firstWhere('type', 'hunter_shot');
                if ($hunterEvent) {
                    $shooterName = $hunterEvent->actor?->name ?? 'The Hunter';
                    $shotTargetName = $hunterEvent->target?->name ?? 'someone';
                    $parts[] = "{$shooterName} was the Hunter — with their dying breath, they shot and killed {$shotTargetName}!";
                }
                break;

            case 'day_discussion':
                $parts[] = "Announce: Day {$round} discussion begins. {$aliveCount} players remain.";
                if ($round === 1) {
                    $parts[] = 'The village gathers for the first time. Everyone must introduce themselves and share their suspicions.';
                } else {
                    $deathEvent = $recentEvents->firstWhere('type', 'death');
                    if ($deathEvent) {
                        $targetName = $deathEvent->target?->name ?? 'someone';
                        $parts[] = "After the loss of {$targetName}, the village must figure out who the werewolves are.";
                    } else {
                        $parts[] = 'The village must discuss and try to identify the werewolves among them.';
                    }
                }
                break;

            case 'day_voting':
                $parts[] = "Announce: Discussion is over. It's time to vote.";
                $parts[] = "Each player will now nominate someone for elimination, then the village will hold a trial.";
                break;

            case 'game_over':
                $parts[] = 'Announce: The game is over!';
                $gameEnd = $recentEvents->firstWhere('type', 'game_end');
                if ($gameEnd) {
                    $parts[] = $gameEnd->data['message'];
                }
                $winner = $game->winner?->value ?? 'unknown';
                if ($winner === 'village') {
                    $parts[] = 'The village has won — all werewolves have been eliminated!';
                } elseif ($winner === 'werewolf') {
                    $parts[] = 'The werewolves have won — they outnumber the villagers!';
                } elseif ($winner === 'neutral') {
                    $parts[] = 'The Tanner wins — they tricked the village into eliminating them!';
                }
                // List surviving players
                $survivors = $game->alivePlayers()->pluck('name')->implode(', ');
                if ($survivors) {
                    $parts[] = "Survivors: {$survivors}.";
                }
                break;
        }

        return implode(' ', $parts);
    }
}
