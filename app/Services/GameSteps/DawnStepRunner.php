<?php

namespace App\Services\GameSteps;

use App\Contracts\Game\PhaseStepRunner;
use App\Enums\DawnFollowUpStep;
use App\Enums\GameEventType;
use App\Events\PlayerActed;
use App\Events\PlayerEliminated;
use App\Models\Game;
use App\Models\Player;
use App\Services\EliminationService;
use App\Services\GameEngine;
use App\States\GamePhase\DayDiscussion;

class DawnStepRunner implements PhaseStepRunner
{
    public function __construct(
        protected EliminationService $eliminationService,
    ) {}

    public function run(Game $game, GameEngine $engine): bool
    {
        $resolution = $engine->getOrCreateDawnResolution($game);
        $dawnEvents = $game->events()
            ->where('round', $game->round)
            ->where('phase', $game->phase->getValue())
            ->whereIn('type', [
                GameEventType::Death->value,
                GameEventType::BodyguardSave->value,
                GameEventType::NoDeath->value,
            ])
            ->orderBy('id')
            ->get()
            ->values();

        if ($game->phase_step < $dawnEvents->count()) {
            $event = $dawnEvents->get($game->phase_step);

            if ($event->type === GameEventType::Death->value) {
                broadcast(new PlayerEliminated(
                    $game->id,
                    $event->toData(),
                    $event->target_player_id,
                    $event->data['role_revealed'] ?? 'unknown',
                ));
            } else {
                broadcast(new PlayerActed($game->id, $event->toData()));
            }

            $engine->addDelaySeconds(2);

            return false;
        }

        $followUpBase = (int) $dawnEvents->count();
        $relativeStep = $game->phase_step - $followUpBase;
        $followUp = DawnFollowUpStep::tryFrom($relativeStep);

        if ($followUp === DawnFollowUpStep::Narration) {
            $engine->generateAndBroadcastNarration($game);

            return false;
        }

        $killedPlayerId = $resolution['killed_player_id'] ?? null;
        $killedPlayer = $killedPlayerId ? Player::find($killedPlayerId) : null;

        if ($killedPlayer) {
            if ($followUp === DawnFollowUpStep::DyingSpeech) {
                $engine->giveDyingSpeech($game, $killedPlayer);

                return false;
            }

            if ($followUp === DawnFollowUpStep::EliminationFollowUp) {
                $engine->processEliminationFollowUp($game, $killedPlayer);

                return false;
            }

            if ($followUp === DawnFollowUpStep::HunterShotDyingSpeech) {
                $this->eliminationService->giveHunterShotDyingSpeech($game, $engine);

                return false;
            }
        }

        if ($engine->checkWinCondition($game)) {
            return true;
        }

        $engine->transitionToPhase($game, DayDiscussion::class);

        return true;
    }
}
