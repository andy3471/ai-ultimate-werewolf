<?php

namespace App\Services;

use App\Ai\Agents\DiscussionAgent;
use App\Ai\Agents\NightActionAgent;
use App\Ai\Context\GameContext;
use App\Enums\GameRole;
use App\Events\PlayerActed;
use App\Events\PlayerEliminated;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class EliminationService
{
    public function __construct(
        protected GameContext $gameContext,
        protected NarrationAudioService $narrationAudioService,
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
                'type' => 'dying_speech',
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

    public function processHunterRevengeShot(Game $game, Player $deadPlayer, GameEngine $engine): void
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

            $targetId = $engine->resolveTargetId($result['target_id'], $game, excludePlayerId: $deadPlayer->id);
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

                $this->narrationAudioService->generateAndAttachAudio($event, $deadPlayer, $hunterMessage);
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $target->id,
                    $target->role->value,
                ));

                $engine->addDelaySeconds($this->narrationAudioService->consumeWaitDelaySeconds());
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to process Hunter revenge', [
                'player_id' => $deadPlayer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
