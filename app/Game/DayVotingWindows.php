<?php

namespace App\Game;

final readonly class DayVotingWindows
{
    public function __construct(
        public int $playerCount,
        public int $nominationResultStep,
    ) {}

    public static function beforeNominationResult(int $playerCount): self
    {
        return new self($playerCount, $playerCount);
    }

    public static function afterNominationResult(int $playerCount, int $nominationResultStep): self
    {
        return new self($playerCount, $nominationResultStep);
    }

    public function nominationCutoff(): int
    {
        return $this->playerCount;
    }

    public function secondWindowStart(): int
    {
        return $this->nominationResultStep + 1;
    }

    public function secondWindowEnd(): int
    {
        return $this->secondWindowStart() + $this->playerCount;
    }

    public function isCollectingNominations(int $phaseStep): bool
    {
        return $phaseStep < $this->nominationCutoff();
    }

    public function isPastNominationCutoff(int $phaseStep): bool
    {
        return $phaseStep >= $this->nominationCutoff();
    }

    public function isCollectingSeconds(int $phaseStep): bool
    {
        return $phaseStep >= $this->secondWindowStart()
            && $phaseStep < $this->secondWindowEnd();
    }

    public function isPastSecondWindow(int $phaseStep): bool
    {
        return $phaseStep >= $this->secondWindowEnd();
    }

    public function seconderIndex(int $phaseStep): int
    {
        return $phaseStep - $this->secondWindowStart();
    }
}
