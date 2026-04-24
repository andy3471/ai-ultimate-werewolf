<?php

namespace App\Services;

use App\Ai\Agents\DiscussionAgent;
use App\Ai\Agents\VoteAgent;
use App\Ai\Context\GameContext;
use App\Events\PlayerActed;
use App\Events\PlayerEliminated;
use App\Models\Game;
use App\Models\Player;
use App\States\GamePhase\DayDiscussion;
use Illuminate\Support\Collection;

class DayActionService
{
    public function __construct(
        protected GameContext $gameContext,
    ) {}

    /**
     * @param  Collection<int,Player>  $alivePlayers
     * @return array{opening_order: array<int,string>, total_budget:int}
     */
    public function getOrCreateDiscussionPlan(Game $game, Collection $alivePlayers): array
    {
        $planEvent = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'discussion_plan')
            ->latest('id')
            ->first();

        if ($planEvent) {
            return $planEvent->data;
        }

        $openingOrder = $alivePlayers->shuffle()->pluck('id')->values()->all();
        $totalBudget = $alivePlayers->count() * 2;

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'discussion_plan',
            'data' => [
                'opening_order' => $openingOrder,
                'total_budget' => $totalBudget,
            ],
            'is_public' => false,
        ]);

        return $event->data;
    }

    public function createDiscussionMessage(Game $game, Player $speaker, string $prompt, GameEngine $engine): void
    {
        $context = $this->gameContext->buildForPlayer($game, $speaker);
        $result = DiscussionAgent::make(
            player: $speaker,
            game: $game,
            context: $context,
        )->prompt(
            $prompt,
            provider: $speaker->provider,
            model: $speaker->model,
        );

        $wantsToSpeak = $result['wants_to_speak'] ?? true;
        if (! $wantsToSpeak) {
            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'discussion_pass',
                'actor_player_id' => $speaker->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                ],
                'is_public' => false,
            ]);

            return;
        }

        $message = $result['message'] ?? (string) $result;
        $addressedId = $engine->resolveAddressedPlayerId($result['addressed_player_id'] ?? 0, $game, $speaker->id);
        $event = $engine->recordDiscussionEvent($game, $speaker, $message, $result, $addressedId);
        broadcast(new PlayerActed($game->id, $event->toData()));
        $engine->waitForAudio();
    }

    public function createNomination(Game $game, Player $player, GameEngine $engine): void
    {
        $context = $this->gameContext->buildForPlayer($game, $player);
        $result = VoteAgent::make(
            player: $player,
            game: $game,
            context: $context,
        )->prompt(
            'Nominate a player you want to put on trial for elimination. If you think discussion should continue without a nomination yet, set target_id to 0.',
            provider: $player->provider,
            model: $player->model,
        );

        $requestedTarget = (int) ($result['target_id'] ?? 0);
        if ($requestedTarget <= 0) {
            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'nomination_skip',
                'actor_player_id' => $player->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                ],
                'is_public' => false,
            ]);

            return;
        }

        $targetId = $engine->resolveTargetId($requestedTarget, $game, excludePlayerId: $player->id);
        if (! $targetId) {
            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'nomination_skip',
                'actor_player_id' => $player->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                ],
                'is_public' => false,
            ]);

            return;
        }

        $reasoning = $result['public_reasoning'] ?? '';
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'nomination',
            'actor_player_id' => $player->id,
            'target_player_id' => $targetId,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $reasoning,
            ],
            'is_public' => true,
        ]);

        if (! empty($reasoning)) {
            $engine->generateAndAttachAudio($event, $player, $reasoning);
        }

        broadcast(new PlayerActed($game->id, $event->toData()));

        if (! empty($reasoning)) {
            $engine->waitForAudio();
        }
    }

    /**
     * @param  Collection<int,Player>  $alivePlayers
     * @return array{accused: Player, nominator_id: string}|null
     */
    public function createNominationResult(Game $game, Collection $alivePlayers): ?array
    {
        $blockedNomineeIds = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'nomination_block')
            ->pluck('target_player_id')
            ->filter()
            ->values()
            ->all();

        $latestNomination = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'nomination')
            ->when($blockedNomineeIds !== [], function ($query) use ($blockedNomineeIds) {
                $query->whereNotIn('target_player_id', $blockedNomineeIds);
            })
            ->latest('id')
            ->first();

        if (! $latestNomination?->target_player_id) {
            return null;
        }

        $accused = $alivePlayers->firstWhere('id', $latestNomination->target_player_id);
        if (! $accused) {
            return null;
        }

        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'nomination_result',
            'target_player_id' => $accused->id,
            'data' => [
                'message' => "{$accused->name} has been nominated for trial and awaits a second.",
                'nominator_id' => $latestNomination->actor_player_id,
            ],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $event->toData()));

        return [
            'accused' => $accused,
            'nominator_id' => (string) $latestNomination->actor_player_id,
        ];
    }

    public function createNominationSecond(
        Game $game,
        Player $seconder,
        Player $accused,
        string $nominatorId,
        GameEngine $engine,
    ): bool {
        if ($seconder->id === $nominatorId) {
            return false;
        }

        $context = $this->gameContext->buildForPlayer($game, $seconder);
        $accusedNumber = $accused->order + 1;
        $context .= "\n\n## SECONDING DECISION\n{$accused->name} [{$accusedNumber}] has been nominated. Choose whether to second this nomination now.\nSet target_id={$accusedNumber} to second, or target_id=0 to continue discussion.";

        $result = VoteAgent::make(
            player: $seconder,
            game: $game,
            context: $context,
        )->prompt(
            "Do you second the nomination of {$accused->name}? target_id={$accusedNumber} to second, target_id=0 to continue discussion.",
            provider: $seconder->provider,
            model: $seconder->model,
        );

        $seconded = ((int) ($result['target_id'] ?? 0)) === $accusedNumber;
        if (! $seconded) {
            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'nomination_skip',
                'actor_player_id' => $seconder->id,
                'data' => [
                    'thinking' => $result['thinking'] ?? '',
                ],
                'is_public' => false,
            ]);

            return false;
        }

        $reasoning = $result['public_reasoning'] ?? '';
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'nomination_second',
            'actor_player_id' => $seconder->id,
            'target_player_id' => $accused->id,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $reasoning,
                'message' => "{$seconder->name} has seconded the nomination of {$accused->name}.",
            ],
            'is_public' => true,
        ]);

        if (! empty($reasoning)) {
            $engine->generateAndAttachAudio($event, $seconder, $reasoning);
        }

        broadcast(new PlayerActed($game->id, $event->toData()));

        if (! empty($reasoning)) {
            $engine->waitForAudio();
        }

        return true;
    }

    public function createDefenseSpeech(Game $game, Player $accused, GameEngine $engine): void
    {
        $defenseContext = $this->gameContext->buildForPlayer($game, $accused);
        $defenseContext .= "\n\n## YOU ARE ON TRIAL\nThe village has nominated you for elimination. This is your chance to defend yourself. Convince them you are not a werewolf!";

        $defenseResult = DiscussionAgent::make(
            player: $accused,
            game: $game,
            context: $defenseContext,
        )->prompt(
            'You are on trial! Make your defense speech. Convince the village to spare you.',
            provider: $accused->provider,
            model: $accused->model,
        );

        $defenseMessage = $defenseResult['message'] ?? (string) $defenseResult;
        $defenseEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'defense_speech',
            'actor_player_id' => $accused->id,
            'data' => [
                'thinking' => $defenseResult['thinking'] ?? '',
                'message' => $defenseMessage,
            ],
            'is_public' => true,
        ]);

        $engine->generateAndAttachAudio($defenseEvent, $accused, $defenseMessage);
        broadcast(new PlayerActed($game->id, $defenseEvent->toData()));
        $engine->waitForAudio();
    }

    public function createDefenseDiscussionMessage(Game $game, Player $speaker, string $prompt, GameEngine $engine): void
    {
        $context = $this->gameContext->buildForPlayer($game, $speaker);
        $result = DiscussionAgent::make(
            player: $speaker,
            game: $game,
            context: $context,
        )->prompt(
            $prompt,
            provider: $speaker->provider,
            model: $speaker->model,
        );

        $message = $result['message'] ?? (string) $result;
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'defense_speech',
            'actor_player_id' => $speaker->id,
            'data' => [
                'thinking' => $result['thinking'] ?? '',
                'message' => $message,
            ],
            'is_public' => true,
        ]);

        $engine->generateAndAttachAudio($event, $speaker, $message);
        broadcast(new PlayerActed($game->id, $event->toData()));
        $engine->waitForAudio();
    }

    public function createTrialVote(Game $game, Player $voter, Player $accused, GameEngine $engine): void
    {
        $accusedNumber = $accused->order + 1;
        $voteContext = $this->gameContext->buildForPlayer($game, $voter);
        $voteContext .= "\n\n## TRIAL VOTE\n{$accused->name} [{$accusedNumber}] is on trial. You heard their defense. Vote YES to eliminate them or NO to spare them.\nSet target_id to {$accusedNumber} if you vote YES (eliminate), or 0 if you vote NO (spare).";

        $voteResult = VoteAgent::make(
            player: $voter,
            game: $game,
            context: $voteContext,
        )->prompt(
            "Vote on {$accused->name}'s fate. target_id={$accusedNumber} for YES (eliminate), target_id=0 for NO (spare).",
            provider: $voter->provider,
            model: $voter->model,
        );

        $votedYes = ((int) ($voteResult['target_id'] ?? 0)) === $accusedNumber;
        $reasoning = $voteResult['public_reasoning'] ?? '';
        $event = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'vote',
            'actor_player_id' => $voter->id,
            'target_player_id' => $votedYes ? $accused->id : null,
            'data' => [
                'thinking' => $voteResult['thinking'] ?? '',
                'public_reasoning' => $reasoning,
                'vote' => $votedYes ? 'yes' : 'no',
            ],
            'is_public' => true,
        ]);

        if (! empty($reasoning)) {
            $engine->generateAndAttachAudio($event, $voter, $reasoning);
        }

        broadcast(new PlayerActed($game->id, $event->toData()));

        if (! empty($reasoning)) {
            $engine->waitForAudio();
        }
    }

    /**
     * @return array{eliminated_id: string|null}
     */
    public function createTrialOutcome(Game $game, Player $accused, GameEngine $engine): array
    {
        $aliveCount = $game->alivePlayers()->count();
        $requiredYes = (int) floor($aliveCount / 2) + 1;
        $yesVotes = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote')
            ->where('target_player_id', $accused->id)
            ->count();

        $totalVotes = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->where('type', 'vote')
            ->count();
        $noVotes = max(0, $totalVotes - $yesVotes);

        $voteTallyEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'vote_tally',
            'target_player_id' => $accused->id,
            'data' => [
                'message' => "Trial vote for {$accused->name}: {$yesVotes} yes, {$noVotes} no.",
                'yes' => $yesVotes,
                'no' => $noVotes,
                'required_yes' => $requiredYes,
            ],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $voteTallyEvent->toData()));
        $engine->addDelaySeconds(3);

        if ($yesVotes >= $requiredYes) {
            $accused->update(['is_alive' => false]);

            $eliminationEvent = $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'elimination',
                'target_player_id' => $accused->id,
                'data' => [
                    'message' => "{$accused->name} has been eliminated by the village. Their role is confirmed: {$accused->role->value}.",
                    'role_revealed' => $accused->role->value,
                    'votes_yes' => $yesVotes,
                    'votes_no' => $noVotes,
                ],
                'is_public' => true,
            ]);

            broadcast(new PlayerEliminated(
                $game->id,
                $eliminationEvent->toData(),
                $accused->id,
                $accused->role->value,
            ));
            $engine->addDelaySeconds(3);

            $game->events()->create([
                'round' => $game->round,
                'phase' => $game->phase->getValue(),
                'type' => 'vote_outcome',
                'data' => ['eliminated_id' => $accused->id],
                'is_public' => false,
            ]);

            return ['eliminated_id' => $accused->id];
        }

        $noElimEvent = $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'no_elimination',
            'target_player_id' => $accused->id,
            'data' => ['message' => "{$accused->name} has been spared by the village ({$yesVotes} yes, {$noVotes} no)."],
            'is_public' => true,
        ]);

        broadcast(new PlayerActed($game->id, $noElimEvent->toData()));
        $engine->addDelaySeconds(3);

        $game->events()->create([
            'round' => $game->round,
            'phase' => $game->phase->getValue(),
            'type' => 'vote_outcome',
            'data' => ['eliminated_id' => null],
            'is_public' => false,
        ]);

        return ['eliminated_id' => null];
    }

    public function addDiscussionExtension(Game $game, int $aliveCount): void
    {
        $extraBudget = max(2, (int) floor($aliveCount / 2));

        $game->events()->create([
            'round' => $game->round,
            'phase' => DayDiscussion::getMorphClass(),
            'type' => 'discussion_extension',
            'data' => [
                'extra_budget' => $extraBudget,
            ],
            'is_public' => false,
        ]);
    }
}
