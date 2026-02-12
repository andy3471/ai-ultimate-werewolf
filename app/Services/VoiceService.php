<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
}
