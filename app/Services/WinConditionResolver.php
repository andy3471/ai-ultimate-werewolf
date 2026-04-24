<?php

namespace App\Services;

use App\Enums\GameRole;
use App\Enums\GameTeam;
use App\Events\GameEnded;
use App\Models\Game;
use App\Models\Player;
use App\States\GamePhase\GameOver;
use App\States\GameStatus\Finished;

class WinConditionResolver
{
    public function resolve(Game $game, ?Player $eliminatedByVillage = null): bool
    {
        $game->refresh();

        if ($eliminatedByVillage && $eliminatedByVillage->role === GameRole::Tanner) {
            $message = "{$eliminatedByVillage->name} was the Tanner and WANTED to be eliminated! The Tanner wins!";
            $this->finalizeGame($game, GameTeam::Neutral, $message);
            broadcast(new GameEnded($game->id, GameTeam::Neutral->value, "{$eliminatedByVillage->name} was the Tanner and wins!"));

            return true;
        }

        $alive = $game->alivePlayers()->get();
        $werewolvesAlive = $alive->filter(fn (Player $player) => $player->role === GameRole::Werewolf)->count();
        $villagersAlive = $alive->filter(fn (Player $player) => $player->role !== GameRole::Werewolf)->count();

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
