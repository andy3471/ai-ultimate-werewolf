<?php

namespace App\Data\Ai;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;

readonly class NightActionResponse
{
    public function __construct(
        public int $targetId,
        public string $thinking,
        public string $publicReasoning,
    ) {}

    /**
     * @param  array<string, mixed>|ArrayAccess<string, mixed>|Arrayable<string, mixed>|object  $result
     */
    public static function fromAgentResult(array|object $result): self
    {
        if ($result instanceof Arrayable) {
            $data = $result->toArray();
        } elseif ($result instanceof ArrayAccess) {
            $data = [
                'target_id' => $result['target_id'] ?? 0,
                'thinking' => $result['thinking'] ?? '',
                'public_reasoning' => $result['public_reasoning'] ?? '',
            ];
        } elseif (is_array($result)) {
            $data = $result;
        } else {
            $data = (array) $result;
        }

        return new self(
            targetId: (int) ($data['target_id'] ?? 0),
            thinking: (string) ($data['thinking'] ?? ''),
            publicReasoning: (string) ($data['public_reasoning'] ?? ''),
        );
    }
}
