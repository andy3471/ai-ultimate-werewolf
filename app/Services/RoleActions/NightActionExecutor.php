<?php

namespace App\Services\RoleActions;

use App\Ai\Agents\NightActionAgent;
use App\Ai\Context\GameContext;
use App\Data\Ai\NightActionResponse;
use App\Enums\GameEventType;
use App\Events\PlayerActed;
use App\Game\RoleExecution\RoleExecutionContext;
use App\Models\GameEvent;
use App\Models\Player;

class NightActionExecutor
{
    public function __construct(
        protected GameContext $gameContext,
    ) {}

    /**
     * @param  callable(?string, Player): ?string|null  $adjustTargetId
     * @param  callable(?string, Player, NightActionResponse): array<string, scalar|null>|null  $buildExtraData
     */
    public function perform(
        RoleExecutionContext $context,
        string $actionPrompt,
        string $userPrompt,
        GameEventType $eventType,
        string $contextSuffix = '',
        bool $excludeActor = true,
        ?callable $adjustTargetId = null,
        ?callable $buildExtraData = null,
    ): ?GameEvent {
        $actor = $context->actor;
        if (! $actor) {
            return null;
        }

        $promptContext = $this->gameContext->buildForPlayer($context->game, $actor).$contextSuffix;

        $raw = NightActionAgent::make(
            player: $actor,
            game: $context->game,
            context: $promptContext,
            actionPrompt: $actionPrompt,
        )->prompt(
            $userPrompt,
            provider: $actor->provider,
            model: $actor->model,
        );

        $response = NightActionResponse::fromAgentResult($raw);

        $targetId = $context->engine->resolveTargetId(
            $response->targetId,
            $context->game,
            excludePlayerId: $excludeActor ? $actor->id : null,
        );

        if ($adjustTargetId !== null) {
            $targetId = $adjustTargetId($targetId, $actor);
        }

        $extra = $buildExtraData ? $buildExtraData($targetId, $actor, $response) : [];

        $event = $context->game->events()->create([
            'round' => $context->game->round,
            'phase' => $context->game->phase->getValue(),
            'type' => $eventType->value,
            'actor_player_id' => $actor->id,
            'target_player_id' => $targetId,
            'data' => array_merge([
                'thinking' => $response->thinking,
                'public_reasoning' => $response->publicReasoning,
            ], $extra),
            'is_public' => false,
        ]);

        broadcast(new PlayerActed($context->game->id, $event->toData()));

        return $event;
    }
}
