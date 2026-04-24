<?php

namespace App\Game\RoleExecution;

class ValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $reason = null,
    ) {}

    public static function valid(): self
    {
        return new self(valid: true);
    }

    public static function invalid(string $reason): self
    {
        return new self(valid: false, reason: $reason);
    }
}
