<?php

namespace App\Game\RoleExecution;

class RoleActionResult
{
    public function __construct(
        public bool $phaseTransitioned = false,
    ) {}

    public static function transitioned(): self
    {
        return new self(phaseTransitioned: true);
    }

    public static function continue(): self
    {
        return new self(phaseTransitioned: false);
    }
}
