<?php

namespace App\Services;

use App\Enums\GameTeam;
use App\Events\GameEnded;
use App\Models\Game;
use App\Models\Player;
use App\States\GamePhase\GameOver;
use App\States\GameStatus\Finished;

class WinConditionResolver
{
    public function __construct(
        protected RoleRegistry $roleRegistry,
    ) {}

    public function resolve(Game $game, ?Player $eliminatedByVillage = null): bool
    {
        $game->refresh();

        if ($eliminatedByVillage !== null) {
            $outcome = $this->roleRegistry->get($eliminatedByVillage->role)->villageEliminationWinOutcome($game, $eliminatedByVillage);
            if ($outcome !== null) {
                $this->finalizeGame($game, $outcome['team'], $outcome['message']);
                broadcast(new GameEnded($game->id, $outcome['team']->value, $outcome['broadcast_message']));

                return true;
            }
        }

        $alive = $game->alivePlayers()->get();
        $werewolvesAlive = $alive->filter(function (Player $player) {
            return $this->roleRegistry->get($player->role)->team() === GameTeam::Werewolves;
        })->count();
        $villagersAlive = $alive->filter(function (Player $player) {
            return $this->roleRegistry->get($player->role)->team() !== GameTeam::Werewolves;
        })->count();

        if ($werewolvesAlive === 0) {
            $message = 'All werewolves have been eliminated! The village wins!';
            $this->finalizeGame($game, GameTeam::Village, $message);
            broadcast(new GameEnded($game->id, GameTeam::Village->value, $message));

            return true;
        }

        if ($werewolvesAlive >= $villagersAlive) {
            $message = 'The werewolves have taken over the village! Werewolves win!';
            $this->finalizeGame($game, GameTeam::Werewolves, $message);
            broadcast(new GameEnded($game->id, GameTeam::Werewolves->value, $message));

            return true;
        }

        return false;
    }

    protected function finalizeGame(Game $game, GameTeam $winner, string $message): void
    {
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
    }
}
