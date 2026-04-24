<?php

namespace App\Services\GamePipeline;

use App\Contracts\Game\PhaseHandler;
use App\Models\Game;

class PhasePipeline
{
    /**
     * @param  PhaseHandler[]  $handlers
     */
    public function __construct(
        protected array $handlers,
    ) {}

    public function run(Game $game): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($game)) {
                return $handler->run($game);
            }
        }

        return true;
    }
}
