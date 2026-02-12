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
#[Temperature(0.7)]
#[Timeout(120)]
class VoteAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Player $player,
        public Game $game,
        public string $context,
    ) {}

    public function instructions(): Stringable|string
    {
        $role = app(RoleRegistry::class)->get($this->player->role);
        $baseInstructions = $role->baseInstructions();

        return <<<INSTRUCTIONS
        {$baseInstructions}

        Personality: {$this->player->personality}

        {$this->context}

        ## Voting Phase
        It is time to vote. Choose one player to eliminate from the game.
        Consider the discussion that just happened, your knowledge, and your strategy.

        IMPORTANT: You must vote for an alive player. Use their player ID number (shown in brackets like [1]).
        You cannot vote for yourself. You cannot vote for dead players.
        Explain your reasoning publicly (other players will see this).
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thinking' => $schema->string()
                ->description('Your private thought process. Analyze the discussion, consider alliances and suspicions, and decide your vote strategically.')
                ->required(),
            'target_id' => $schema->integer()
                ->description('The ID of the player you are voting to eliminate.')
                ->required(),
            'public_reasoning' => $schema->string()
                ->description('What you say out loud about your vote. This is public and other players will hear it.')
                ->required(),
        ];
    }
}
