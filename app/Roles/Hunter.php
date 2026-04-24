<?php

namespace App\Roles;

use App\Ai\Agents\NightActionAgent;
use App\Ai\Context\GameContext;
use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Events\PlayerEliminated;
use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\Services\NarrationAudioService;
use Illuminate\Support\Facades\Log;

class Hunter extends Role
{
    public function id(): GameRole
    {
        return GameRole::Hunter;
    }

    public function name(): string
    {
        return 'Hunter';
    }

    public function team(): GameTeam
    {
        return GameTeam::Village;
    }

    public function baseInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are the Hunter in a game of Ultimate Werewolf. You are on the Village team.
        Your goal is to identify and eliminate the werewolves through discussion and voting.

        **Your special ability:** When you are eliminated (whether by werewolf attack at night or
        by village vote during the day), you get to take one other player down with you.
        You will choose someone to "shoot" with your dying action.

        Strategy tips:
        - Pay close attention to who seems suspicious — if you die, your revenge shot is powerful.
        - You can reveal you are the Hunter to deter werewolves from targeting you (risky but effective).
        - If you are on trial, reminding people you will shoot someone if eliminated can be persuasive.
        - Your shot should ideally target someone you believe is a werewolf.
        - The village uses a nomination → trial → vote system. Participate actively in discussions.
        INSTRUCTIONS;
    }

    public function rulesPrompt(): string
    {
        return 'Hunter (Village team): If eliminated (day or night), immediately shoots one player, eliminating them as well. Wins when all werewolves are eliminated.';
    }

    public function onElimination(Game $game, Player $eliminated, GameEngine $engine): void
    {
        try {
            $gameContext = app(GameContext::class)->buildForPlayer($game, $eliminated);
            $gameContext .= "\n\n## HUNTER'S REVENGE\nYou have been eliminated, but as the Hunter, you get to take one player down with you! Choose wisely — target who you believe is a werewolf.";

            $result = NightActionAgent::make(
                player: $eliminated,
                game: $game,
                context: $gameContext,
                actionPrompt: 'You are the Hunter. Choose one alive player to shoot with your dying action. Pick who you believe is a werewolf.',
            )->prompt(
                'Choose a player to take down with you.',
                provider: $eliminated->provider,
                model: $eliminated->model,
            );

            $targetId = $engine->resolveTargetId($result['target_id'], $game, excludePlayerId: $eliminated->id);
            $target = $targetId ? Player::find($targetId) : null;

            if ($target && $target->is_alive) {
                $target->update(['is_alive' => false]);

                $hunterMessage = "{$eliminated->name} was the Hunter and shoots {$target->name} with their dying breath! {$target->name} was a {$target->role->value}.";
                $event = $game->events()->create([
                    'round' => $game->round,
                    'phase' => $game->phase->getValue(),
                    'type' => 'hunter_shot',
                    'actor_player_id' => $eliminated->id,
                    'target_player_id' => $target->id,
                    'data' => [
                        'thinking' => $result['thinking'] ?? '',
                        'public_reasoning' => $result['public_reasoning'] ?? '',
                        'message' => $hunterMessage,
                        'role_revealed' => $target->role->value,
                    ],
                    'is_public' => true,
                ]);

                $narration = app(NarrationAudioService::class);
                $narration->generateAndAttachAudio($event, $eliminated, $hunterMessage);
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $target->id,
                    $target->role->value,
                ));

                $engine->addDelaySeconds($narration->consumeWaitDelaySeconds());
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to process Hunter revenge', [
                'player_id' => $eliminated->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
