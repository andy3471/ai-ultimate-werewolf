<?php

namespace App\Ai\Agents;

use App\Models\Game;
use App\Models\Player;
use App\Services\RoleRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxTokens(1024)]
#[Temperature(0.8)]
#[Timeout(120)]
class NightActionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Player $player,
        public Game $game,
        public string $context,
        public string $actionPrompt,
    ) {}

    public function instructions(): Stringable|string
    {
        $role = app(RoleRegistry::class)->get($this->player->role);
        $baseInstructions = $role->baseInstructions();

        return <<<INSTRUCTIONS
        {$baseInstructions}

        Personality: {$this->player->personality}

        {$this->context}

        ## Your Night Action
        {$this->actionPrompt}

        IMPORTANT: You must choose a valid target from the alive players listed above.
        Use the player number (shown in brackets like [1]) as target_id.
        Think carefully about your choice and explain your reasoning.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thinking' => $schema->string()
                ->description('Your private internal thought process. Analyze the situation, consider your options, and reason about the best choice.')
                ->required(),
            'target_id' => $schema->integer()
                ->description('The player number (shown in brackets) of the player you are targeting.')
                ->required(),
            'public_reasoning' => $schema->string()
                ->description('A brief justification for your choice (stored for post-game review).')
                ->required(),
        ];
    }
}
