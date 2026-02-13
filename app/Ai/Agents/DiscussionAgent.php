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

        $isFirstRound = $this->game->round <= 1;

        $discussionGuidance = $isFirstRound
            ? <<<'EARLY'
            ## Day Discussion — Early Game
            It is now the day phase. This is early in the game, so there may be limited evidence.

            IMPORTANT RULES FOR NATURAL PLAY:
            - If someone died and a dying player made a claim (e.g. "I was the Seer, player X is a wolf"),
              you MUST engage with that information. Take a position — do you believe them or not? Why?
              Challenge the accused player directly. Do NOT just say "let's think about this" or "how do we feel?"
            - Do NOT invent evidence that doesn't exist. Don't accuse someone of "being quiet" or
              "acting suspicious" when nobody has spoken yet.
            - Do NOT all say the same vague thing. Take a DISTINCT position. Disagree with each other.
              Some players should believe claims, others should be sceptical, others should push back.
            - Be opinionated and direct. Real Werewolf players argue, push, and take sides — they don't
              politely ask "how is everyone feeling?" in unison.

            React to what actually happened overnight. If there's a death, a claim, or a dying speech,
            that IS your evidence — use it.
            EARLY
            : <<<'LATE'
            ## Day Discussion
            It is now the day phase. Share your thoughts with the other players.
            You should discuss who you think is suspicious and why, defend yourself if accused,
            and try to influence the upcoming vote. Base your suspicions on actual observations:
            what people said, how they voted, patterns in the deaths, or contradictions you noticed.
            Be direct and opinionated — take a clear position rather than being vaguely diplomatic.
            LATE;

        return <<<INSTRUCTIONS
        {$baseInstructions}

        Personality: {$this->player->personality}

        {$this->context}

        {$discussionGuidance}

        Keep your message concise (2-4 sentences). Speak naturally as your character would.
        Remember your personality and role when deciding what to say and how to say it.

        NEVER ask generic questions like "how is everyone feeling?" or "what do we think?"
        Instead, state YOUR opinion, make YOUR case, or challenge a specific player.
        Every message should move the game forward with a clear point of view.

        If you are a werewolf, you need to blend in and deflect suspicion — but do it actively,
        not by being vague. Throw suspicion on someone else, poke holes in claims, take a side.
        If you are a villager/seer/bodyguard/hunter, try to identify and expose the werewolves.

        ## Addressing Other Players
        You can direct a question or challenge to a specific player by setting addressed_player_id
        to their player number (shown in brackets like [1]). That player will then get a chance to respond next.
        Set addressed_player_id to 0 if you are not addressing anyone specific.

        ## Passing
        If you have already said everything you want to say and have nothing new to add,
        you may pass by setting wants_to_speak to false. Only pass if you genuinely have
        nothing meaningful to contribute — otherwise, engage with the conversation.
        In the opening round you MUST speak (wants_to_speak = true).
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thinking' => $schema->string()
                ->description('Your private inner monologue. Consider your strategy: what do you know, what should you reveal or hide, how should you influence the group?')
                ->required(),
            'message' => $schema->string()
                ->description('Your public statement to the group. This is what the other players will hear. If passing, leave this brief or empty.')
                ->required(),
            'addressed_player_id' => $schema->integer()
                ->description('The player number (shown in brackets) you are directing a question or challenge to. Set to 0 if not addressing anyone specific.')
                ->required(),
            'wants_to_speak' => $schema->boolean()
                ->description('Set to true to speak, false to pass (skip your turn). You must speak in the opening round.')
                ->required(),
        ];
    }
}
