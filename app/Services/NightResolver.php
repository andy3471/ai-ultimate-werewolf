<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;

class NightResolver
{
    /**
     * Resolve all night actions for the given round.
     * Returns an array of results: kills, protections, investigations.
     *
     * @return array{killed: Player|null, protected: Player|null, events: GameEvent[]}
     */
    public function resolve(Game $game): array
    {
        $round = $game->round;

        // Gather night actions for this round
        $werewolfAction = $game->events()
            ->where('round', $round)
            ->where('type', 'werewolf_kill')
            ->first();

        $bodyguardAction = $game->events()
            ->where('round', $round)
            ->where('type', 'bodyguard_protect')
            ->first();

        $killed = null;
        $protected = null;
        $events = [];

        // Determine the werewolf target
        $targetId = $werewolfAction?->target_player_id;

        // Determine if bodyguard protected the target
        $protectedId = $bodyguardAction?->target_player_id;

        if ($protectedId) {
            $protected = Player::find($protectedId);
        }

        if ($targetId) {
            if ($targetId === $protectedId) {
                // Bodyguard saved the target
                $events[] = $game->events()->create([
                    'round' => $round,
                    'phase' => 'dawn',
                    'type' => 'bodyguard_save',
                    'target_player_id' => $targetId,
                    'data' => ['message' => 'The Bodyguard saved someone during the night!'],
                    'is_public' => true,
                ]);
            } else {
                // Player dies
                $killed = Player::find($targetId);

                if ($killed) {
                    $killed->update(['is_alive' => false]);

                    $events[] = $game->events()->create([
                        'round' => $round,
                        'phase' => 'dawn',
                        'type' => 'death',
                        'target_player_id' => $killed->id,
                        'data' => [
                            'message' => "{$killed->name} was killed by the werewolves during the night. Their role is revealed: they were the {$killed->role->value}.",
                            'role_revealed' => $killed->role->value,
                        ],
                        'is_public' => true,
                    ]);
                }
            }
        }

        // If nobody was killed (no target or saved)
        if (! $killed && $targetId) {
            $events[] = $game->events()->create([
                'round' => $round,
                'phase' => 'dawn',
                'type' => 'no_death',
                'data' => ['message' => 'The village wakes up and everyone is alive!'],
                'is_public' => true,
            ]);
        } elseif (! $targetId) {
            $events[] = $game->events()->create([
                'round' => $round,
                'phase' => 'dawn',
                'type' => 'no_death',
                'data' => ['message' => 'A peaceful night. No one was harmed.'],
                'is_public' => true,
            ]);
        }

        return [
            'killed' => $killed,
            'protected' => $protected,
            'events' => $events,
        ];
    }
}
