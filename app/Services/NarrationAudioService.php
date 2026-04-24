<?php

namespace App\Services;

use App\Events\GamePhaseChanged;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;

class NarrationAudioService
{
    protected float $lastAudioDuration = 0;

    public function __construct(
        protected VoiceService $voiceService,
    ) {}

    public function generateAndAttachAudio(GameEvent $event, Player $player, string $text): void
    {
        $this->lastAudioDuration = 0;

        $result = $this->voiceService->generateSpeech($player, $text, $event->id);

        if ($result) {
            $event->update(['audio_url' => $result['url']]);
            $this->lastAudioDuration = $result['duration'];
        }
    }

    public function consumeWaitDelaySeconds(): int
    {
        if ($this->lastAudioDuration > 0) {
            $delay = (int) ceil($this->lastAudioDuration) + 2;
            $this->lastAudioDuration = 0;

            return $delay;
        }

        $this->lastAudioDuration = 0;

        return $this->voiceService->isAvailable() ? 4 : 1;
    }

    public function broadcastPhaseChange(Game $game, bool $narrate = true): int
    {
        $game->refresh();
        $phase = $game->phase;
        $narrationText = null;
        $narrationAudioUrl = null;
        $delaySeconds = 0;

        if ($narrate) {
            $narration = $this->voiceService->narrate($game, $phase);

            if ($narration) {
                $narrationText = $narration['text'];
                $narrationAudioUrl = $narration['url'];
                $game->events()->create([
                    'round' => $game->round,
                    'phase' => $phase->getValue(),
                    'type' => 'narration',
                    'data' => ['message' => $narrationText],
                    'audio_url' => $narrationAudioUrl,
                    'is_public' => true,
                ]);
                $this->lastAudioDuration = $narration['duration'];
                $delaySeconds += $this->consumeWaitDelaySeconds();
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

        return $delaySeconds;
    }

    public function generateAndBroadcastNarration(Game $game): int
    {
        $game->refresh();
        $phase = $game->phase;
        $narration = $this->voiceService->narrate($game, $phase);

        if (! $narration) {
            return 0;
        }

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $phase->getValue(),
            'type' => 'narration',
            'data' => ['message' => $narration['text']],
            'audio_url' => $narration['url'],
            'is_public' => true,
        ]);

        broadcast(new GamePhaseChanged(
            $game->id,
            $phase->getValue(),
            $game->round,
            $phase->description(),
            narration: $narration['text'],
            narration_audio_url: $narration['url'],
        ));
        broadcast(new \App\Events\PlayerActed($game->id, $event->toData()));

        $this->lastAudioDuration = $narration['duration'];

        return $this->consumeWaitDelaySeconds();
    }
}
