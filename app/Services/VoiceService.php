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
     * Assign a unique voice to each player in the game.
     * Voices are stored as "provider:voice_id" (e.g. "openai:nova").
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

        foreach ($players as $index => $player) {
            $voiceId = $voices[$index % count($voices)];
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
You are the narrator of a Werewolf social deduction game. Generate a single dramatic, atmospheric narration line (1-3 sentences max) for the given phase transition.

Rules:
- Be creative, atmospheric, and concise
- Use vivid imagery: mention the moon, shadows, firelight, whispers, etc.
- Reference the specific events described (names of who died, who was saved, who won, etc.)
- Vary your style — don't always start with the same words
- Match the mood: night phases are eerie and tense, day phases are urgent and suspicious, dawn is revelatory, game over is conclusive
- Do NOT reveal hidden roles unless the context says to (e.g. don't say "the werewolf" unless they were already exposed)
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

        $parts = ["Round {$round}. {$aliveCount} players remain alive."];

        switch ($phase) {
            case 'night_werewolf':
                if ($round === 1) {
                    $playerNames = $game->players()->pluck('name')->implode(', ');
                    $parts[] = "The first night falls on the village. The players are: {$playerNames}. The werewolves awaken to learn who their pack members are.";
                } else {
                    $parts[] = 'Night falls on the village. The werewolves open their eyes and must choose their next victim.';
                    // Mention what happened during the day
                    $dayEvent = $recentEvents->firstWhere('type', 'elimination');
                    if ($dayEvent) {
                        $parts[] = "Earlier today, {$dayEvent->data['message']}";
                    }
                    $noElim = $recentEvents->firstWhere('type', 'no_elimination');
                    if ($noElim) {
                        $parts[] = "The village could not agree — {$noElim->data['message']}";
                    }
                }
                break;

            case 'dawn':
                $parts[] = 'The sun rises over the village.';
                // Check for deaths/saves from the night
                $deathEvent = $recentEvents->firstWhere('type', 'death');
                $saveEvent = $recentEvents->firstWhere('type', 'bodyguard_save');
                $noDeathEvent = $recentEvents->firstWhere('type', 'no_death');

                if ($deathEvent) {
                    $targetName = $deathEvent->target?->name ?? 'someone';
                    $parts[] = "{$targetName} was found dead, taken by the werewolves in the night.";
                } elseif ($saveEvent) {
                    $parts[] = 'Miraculously, no one was killed — the bodyguard protected the right person.';
                } elseif ($noDeathEvent) {
                    $parts[] = 'The village awakens unharmed. No one was killed in the night.';
                }

                $hunterEvent = $recentEvents->firstWhere('type', 'hunter_shot');
                if ($hunterEvent) {
                    $parts[] = $hunterEvent->data['message'] ?? '';
                }
                break;

            case 'day_discussion':
                $parts[] = 'The villagers gather to discuss who among them might be a werewolf.';
                if ($round === 1) {
                    $parts[] = 'Suspicion and fear fill the air as accusations begin to fly.';
                } else {
                    $parts[] = 'Tensions are running high. Someone must answer for last night.';
                }
                break;

            case 'day_voting':
                $parts[] = 'The time for talk is over. The village must decide whether to put someone on trial.';
                break;

            case 'game_over':
                $gameEnd = $recentEvents->firstWhere('type', 'game_end');
                if ($gameEnd) {
                    $parts[] = "The game has ended. {$gameEnd->data['message']}";
                } else {
                    $winner = $game->winner?->value ?? 'unknown';
                    $parts[] = "The game has ended. The {$winner} team is victorious.";
                }
                break;
        }

        return implode(' ', $parts);
    }
}
