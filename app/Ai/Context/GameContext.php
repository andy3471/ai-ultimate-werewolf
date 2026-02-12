<?php

namespace App\Ai\Context;

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Services\RoleRegistry;

class GameContext
{
    public function __construct(
        protected RoleRegistry $roleRegistry,
    ) {}

    /**
     * Build the game context string from the perspective of a given player.
     * Only includes information the player would legitimately know.
     */
    public function buildForPlayer(Game $game, Player $player): string
    {
        $sections = [];

        // Game overview
        $sections[] = $this->buildGameOverview($game, $player);

        // Player list
        $sections[] = $this->buildPlayerList($game, $player);

        // Role-specific knowledge
        $sections[] = $this->buildRoleKnowledge($game, $player);

        // Game history (what this player can see)
        $sections[] = $this->buildHistory($game, $player);

        return implode("\n\n", array_filter($sections));
    }

    protected function buildGameOverview(Game $game, Player $player): string
    {
        $alivePlayers = $game->alivePlayers()->count();
        $totalPlayers = $game->players()->count();
        $deadPlayers = $totalPlayers - $alivePlayers;

        return <<<CONTEXT
        ## Game State
        - Round: {$game->round}
        - Current Phase: {$game->phase->label()}
        - Players Alive: {$alivePlayers} / {$totalPlayers}
        - Players Dead: {$deadPlayers}
        - Your Name: {$player->name}
        - Your Role: {$player->role->value}
        CONTEXT;
    }

    protected function buildPlayerList(Game $game, Player $player): string
    {
        $lines = ["## Players"];

        foreach ($game->players as $p) {
            $status = $p->is_alive ? 'ALIVE' : 'DEAD';
            $roleInfo = '';

            if (! $p->is_alive) {
                $roleInfo = " (was {$p->role->value})";
            } elseif ($p->id === $player->id) {
                $roleInfo = " (you - {$p->role->value})";
            }

            $lines[] = "- [{$p->id}] {$p->name} - {$status}{$roleInfo}";
        }

        return implode("\n", $lines);
    }

    protected function buildRoleKnowledge(Game $game, Player $player): string
    {
        $knowledge = [];

        // Werewolves know each other
        if ($player->role === GameRole::Werewolf) {
            $fellowWolves = $game->players()
                ->where('role', GameRole::Werewolf->value)
                ->where('id', '!=', $player->id)
                ->get();

            if ($fellowWolves->isNotEmpty()) {
                $names = $fellowWolves->pluck('name')->implode(', ');
                $knowledge[] = "## Secret Knowledge\nYour fellow werewolf(s): {$names}. Coordinate to eliminate villagers without being discovered.";
            }
        }

        // Seer's investigation results
        if ($player->role === GameRole::Seer) {
            $investigations = $game->events()
                ->where('actor_player_id', $player->id)
                ->where('type', 'seer_investigate')
                ->get();

            if ($investigations->isNotEmpty()) {
                $results = ["## Your Investigation Results"];
                foreach ($investigations as $event) {
                    $result = $event->data['result'] ?? 'Unknown';
                    $results[] = "- Round {$event->round}: {$result}";
                }
                $knowledge[] = implode("\n", $results);
            }
        }

        return implode("\n\n", $knowledge);
    }

    protected function buildHistory(Game $game, Player $player): string
    {
        $events = $game->events()
            ->where(function ($query) use ($player) {
                $query->where('is_public', true)
                    ->orWhere('actor_player_id', $player->id);
            })
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return '';
        }

        $lines = ["## Game History"];
        $currentRound = 0;

        foreach ($events as $event) {
            if ($event->round !== $currentRound) {
                $currentRound = $event->round;
                $lines[] = "\n### Round {$currentRound}";
            }

            $line = $this->formatEvent($event, $player);
            if ($line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    protected function formatEvent(GameEvent $event, Player $player): ?string
    {
        return match ($event->type) {
            'discussion' => $this->formatDiscussion($event),
            'vote' => $this->formatVote($event),
            'death' => $event->data['message'] ?? null,
            'elimination' => $event->data['message'] ?? null,
            'doctor_save' => $event->data['message'] ?? null,
            'no_death' => $event->data['message'] ?? null,
            'vote_tally' => $event->data['message'] ?? null,
            'vote_tie' => $event->data['message'] ?? null,
            'no_elimination' => $event->data['message'] ?? null,
            'seer_investigate' => $event->actor_player_id === $player->id
                ? "You investigated and learned: ".($event->data['result'] ?? 'nothing')
                : null,
            'game_end' => $event->data['message'] ?? null,
            default => null,
        };
    }

    protected function formatDiscussion(GameEvent $event): ?string
    {
        $actor = $event->actor;
        $name = $actor ? $actor->name : 'Unknown';
        $message = $event->data['message'] ?? '';

        return "**{$name}**: {$message}";
    }

    protected function formatVote(GameEvent $event): ?string
    {
        $actor = $event->actor;
        $target = $event->target;
        $actorName = $actor ? $actor->name : 'Unknown';
        $targetName = $target ? $target->name : 'Unknown';
        $reasoning = $event->data['public_reasoning'] ?? '';

        return "**{$actorName}** voted to eliminate **{$targetName}**".($reasoning ? ": \"{$reasoning}\"" : '');
    }
}
