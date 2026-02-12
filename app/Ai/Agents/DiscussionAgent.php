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
#[Temperature(0.9)]
#[Timeout(120)]
class DiscussionAgent implements Agent, HasStructuredOutput
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

        ## Day Discussion
        It is now the day phase. Share your thoughts with the other players.
        You should discuss who you think is suspicious and why, defend yourself if accused,
        and try to influence the upcoming vote.

        Keep your message concise (2-4 sentences). Speak naturally as your character would.
        Remember your personality and role when deciding what to say and how to say it.

        If you are a werewolf, you need to blend in and deflect suspicion.
        If you are a villager/seer/doctor, try to identify and expose the werewolves.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thinking' => $schema->string()
                ->description('Your private inner monologue. Consider your strategy: what do you know, what should you reveal or hide, how should you influence the group?')
                ->required(),
            'message' => $schema->string()
                ->description('Your public statement to the group. This is what the other players will hear.')
                ->required(),
        ];
    }
}
