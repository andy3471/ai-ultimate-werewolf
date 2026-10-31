<?php

namespace App\Services;

use App\Ai\Agents\DiscussionAgent;
use App\Ai\Context\GameContext;
use App\Enums\GameEventType;
use App\Events\PlayerActed;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class EliminationService
{
    public function __construct(
        protected GameContext $gameContext,
        protected NarrationAudioService $narrationAudioService,
        protected RoleRegistry $roleRegistry,
    ) {}

    public function giveDyingSpeech(Game $game, Player $player, GameEngine $engine): void
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
                'type' => GameEventType::DyingSpeech->value,
                'actor_player_id' => $player->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                    'message' => $message,
                ],
                'is_public' => true,
            ]);

            $this->narrationAudioService->generateAndAttachAudio($event, $player, $message);

            broadcast(new PlayerActed($game->id, $event->toData()));

            $engine->addDelaySeconds($this->narrationAudioService->consumeWaitDelaySeconds());
        } catch (\Throwable $e) {
            Log::warning('Failed to get dying speech', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function processEliminationFollowUp(Game $game, Player $deadPlayer, GameEngine $engine): void
    {
        $this->roleRegistry->get($deadPlayer->role)->onElimination($game, $deadPlayer, $engine);
    }

    public function giveHunterShotDyingSpeech(Game $game, GameEngine $engine): bool
    {
        if ($game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', GameEventType::HunterShotFollowupDone->value)
            ->exists()) {
            return false;
        }

        $shotEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', GameEventType::HunterShot->value)
            ->latest('id')
            ->first();

        if ($shotEvent?->target_player_id) {
            $shotPlayer = Player::find($shotEvent->target_player_id);
            if ($shotPlayer) {
                $this->giveDyingSpeech($game, $shotPlayer, $engine);
            }
        }

        $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => GameEventType::HunterShotFollowupDone->value,
            'is_public' => false,
        ]);

        return true;
    }
}
