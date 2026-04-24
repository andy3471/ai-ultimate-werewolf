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
        $sections[] = $this->buildGameOverview($game, $player);
        $sections[] = $this->buildRulesReference($game);
        $sections[] = $this->buildRolesInPlay($game);
        $sections[] = $this->buildRoleReference($game);
        $sections[] = $this->buildPlayerList($game, $player);
        $sections[] = $this->buildRoleKnowledge($game, $player);
        $sections[] = $this->buildVotingMemory($game, $player);
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
        - Rule Reminder: There is no werewolf kill on Night 1. Werewolf kills begin on Night 2.
        - Players Alive: {$alivePlayers} / {$totalPlayers}
        - Players Dead: {$deadPlayers}
        - Your Name: {$player->name}
        - Your Role: {$player->role->value}
        CONTEXT;
    }

    protected function buildRulesReference(Game $game): string
    {
        return <<<'RULES'
        ## Game Rules
        - Objective: Village wins by eliminating all werewolves. Werewolves win when they equal or outnumber the village.
        - Night order: Werewolves -> Seer -> Bodyguard, then Dawn resolves outcomes.
        - Night 1 exception: Werewolves only identify each other; there is NO werewolf kill on Night 1.
        - Bodyguard rule: The Bodyguard cannot protect the same player on consecutive nights.
        - Day flow: Discussion -> nominations -> top nominee on trial -> defense speech -> YES/NO vote.
        - Voting rule: A simple majority of YES votes eliminates the accused; otherwise no elimination occurs.
        - Public information: Eliminated players are dead and their role is confirmed publicly.
        RULES;
    }

    protected function buildRolesInPlay(Game $game): string
    {
        $roleDistribution = $game->role_distribution;
        if (! is_array($roleDistribution) || $roleDistribution === []) {
            return '';
        }

        $lines = ['## Roles In Play'];

        foreach ($roleDistribution as $roleName => $count) {
            $lines[] = "- {$count}x {$roleName}";
        }

        return implode("\n", $lines);
    }

    protected function buildRoleReference(Game $game): string
    {
        $roleDistribution = $game->role_distribution;
        if (! is_array($roleDistribution) || $roleDistribution === []) {
            return '';
        }

        $rolesByName = collect($this->roleRegistry->all())
            ->keyBy(fn ($role) => $role->name());

        $lines = ['## Role Reference'];

        foreach ($roleDistribution as $roleName => $count) {
            $role = $rolesByName->get($roleName);
            if (! $role) {
                continue;
            }

            $prompt = trim($role->rulesPrompt());
            if ($prompt === '') {
                continue;
            }

            $lines[] = "- {$roleName}: {$prompt}";
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    protected function buildPlayerList(Game $game, Player $player): string
    {
        $lines = ['## Players'];
        $lines[] = '(Use the number in brackets to reference a player. Dead players\' roles are publicly revealed and confirmed.)';

        foreach ($game->players as $p) {
            $playerNumber = $p->order + 1;
            $status = $p->is_alive ? 'ALIVE' : 'DEAD';
            $roleInfo = '';

            if (! $p->is_alive) {
                $roleInfo = " (Confirmed role: {$p->role->value})";
            } elseif ($p->id === $player->id) {
                $roleInfo = " (you - {$p->role->value})";
            }

            $lines[] = "- [{$playerNumber}] {$p->name} - {$status}{$roleInfo}";
        }

        return implode("\n", $lines);
    }

    protected function buildRoleKnowledge(Game $game, Player $player): string
    {
        $knowledge = [];

        if ($player->role === GameRole::Werewolf) {
            $fellowWolves = $game->players()
                ->where('role', GameRole::Werewolf->value)
                ->where('id', '!=', $player->id)
                ->get();

            if ($fellowWolves->isNotEmpty()) {
                $wolfList = $fellowWolves->map(function (Player $w) {
                    $num = $w->order + 1;

                    return "[{$num}] {$w->name}";
                })->implode(', ');
                $knowledge[] = "## Secret Knowledge\nYour fellow werewolf(s): {$wolfList}. Coordinate to eliminate villagers without being discovered.";
            }
        }

        if ($player->role === GameRole::Seer) {
            $investigations = $game->events()
                ->where('actor_player_id', $player->id)
                ->where('type', 'seer_investigate')
                ->get();

            if ($investigations->isNotEmpty()) {
                $results = ['## Your Investigation Results'];
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

        $lines = ['## Game History'];
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

    protected function buildVotingMemory(Game $game, Player $player): string
    {
        $visibleEvents = $game->events()
            ->where(function ($query) use ($player) {
                $query->where('is_public', true)
                    ->orWhere('actor_player_id', $player->id);
            })
            ->whereIn('type', ['nomination', 'nomination_result', 'vote', 'vote_tally', 'elimination', 'no_elimination'])
            ->orderBy('id')
            ->get();

        if ($visibleEvents->isEmpty()) {
            return '';
        }

        $playersById = $game->players->keyBy('id');
        $roundSummaries = [];

        foreach ($visibleEvents->groupBy('round') as $round => $events) {
            $nominations = $events->where('type', 'nomination');
            $votes = $events->where('type', 'vote');
            $outcomeEvent = $events->first(function (GameEvent $event) {
                return in_array($event->type, ['elimination', 'no_elimination'], true);
            });

            $lineParts = [];

            if ($nominations->isNotEmpty()) {
                $topNomineeId = $nominations
                    ->pluck('target_player_id')
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->keys()
                    ->first();

                if ($topNomineeId) {
                    $topNomineeName = $playersById[$topNomineeId]->name ?? 'Unknown';
                    $lineParts[] = "top nomination: {$topNomineeName}";
                }
            }

            if ($votes->isNotEmpty()) {
                $yesVotes = $votes->where('data.vote', 'yes')->count();
                $noVotes = $votes->where('data.vote', 'no')->count();
                $lineParts[] = "trial votes yes/no: {$yesVotes}/{$noVotes}";
            }

            if ($outcomeEvent) {
                $lineParts[] = $outcomeEvent->type === 'elimination'
                    ? 'outcome: elimination passed'
                    : 'outcome: no elimination';
            }

            if (! empty($lineParts)) {
                $roundSummaries[] = "- Round {$round}: ".implode('; ', $lineParts).'.';
            }
        }

        if (empty($roundSummaries)) {
            return '';
        }

        return "## Past Voting Record\n".implode("\n", $roundSummaries);
    }

    protected function formatEvent(GameEvent $event, Player $player): ?string
    {
        return match ($event->type) {
            'discussion' => $this->formatDiscussion($event),
            'dying_speech' => $this->formatDyingSpeech($event),
            'nomination' => $this->formatNomination($event),
            'nomination_result' => $event->data['message'] ?? null,
            'defense_speech' => $this->formatDefenseSpeech($event),
            'vote' => $this->formatVote($event),
            'death' => $event->data['message'] ?? null,
            'elimination' => $event->data['message'] ?? null,
            'bodyguard_save' => $event->data['message'] ?? null,
            'hunter_shot' => $event->data['message'] ?? null,
            'narration' => null,
            'no_death' => $event->data['message'] ?? null,
            'vote_tally' => $event->data['message'] ?? null,
            'vote_tie' => $event->data['message'] ?? null,
            'no_elimination' => $event->data['message'] ?? null,
            'seer_investigate' => $event->actor_player_id === $player->id
                ? 'You investigated and learned: '.($event->data['result'] ?? 'nothing')
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

        $addressedId = $event->data['addressed_player_id'] ?? null;
        $addressedSuffix = '';
        if ($addressedId) {
            $addressed = $event->target;
            $addressedName = $addressed ? $addressed->name : "Player #{$addressedId}";
            $addressedSuffix = " (→ addressing {$addressedName})";
        }

        return "**{$name}**{$addressedSuffix}: {$message}";
    }

    protected function formatVote(GameEvent $event): ?string
    {
        $actor = $event->actor;
        $actorName = $actor ? $actor->name : 'Unknown';
        $reasoning = $event->data['public_reasoning'] ?? '';

        if (isset($event->data['vote'])) {
            $vote = $event->data['vote'] === 'yes' ? 'ELIMINATE' : 'SPARE';

            return "**{$actorName}** voted to **{$vote}**".($reasoning ? ": \"{$reasoning}\"" : '');
        }

        $target = $event->target;
        $targetName = $target ? $target->name : 'Unknown';

        return "**{$actorName}** voted to eliminate **{$targetName}**".($reasoning ? ": \"{$reasoning}\"" : '');
    }

    protected function formatDyingSpeech(GameEvent $event): ?string
    {
        $actor = $event->actor;
        $name = $actor ? $actor->name : 'Unknown';
        $message = $event->data['message'] ?? '';

        return "💀 **{$name}** (dying words): {$message}";
    }

    protected function formatNomination(GameEvent $event): ?string
    {
        $actor = $event->actor;
        $target = $event->target;
        $actorName = $actor ? $actor->name : 'Unknown';
        $targetName = $target ? $target->name : 'Unknown';
        $reasoning = $event->data['public_reasoning'] ?? '';

        return "**{$actorName}** nominated **{$targetName}** for trial".($reasoning ? ": \"{$reasoning}\"" : '');
    }

    protected function formatDefenseSpeech(GameEvent $event): ?string
    {
        $actor = $event->actor;
        $name = $actor ? $actor->name : 'Unknown';
        $message = $event->data['message'] ?? '';

        return "⚖️ **{$name}** (defense): {$message}";
    }
}
