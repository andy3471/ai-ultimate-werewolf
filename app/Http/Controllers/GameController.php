<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameRequest;
use App\Jobs\RunGame;
use App\Models\Game;
use App\Services\GameSetupService;
use App\States\GameStatus\Finished;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function __construct(
        protected GameSetupService $gameSetupService,
    ) {}

    public function index(): Response
    {
        $games = Game::with('players')
            ->latest()
            ->get()
            ->map(fn (Game $game) => $game->toData());

        return Inertia::render('games/Index', [
            'games' => $games,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('games/Create', [
            'availableProviders' => $this->getAvailableProviders(),
            'availablePersonalities' => $this->getAvailablePersonalities(),
        ]);
    }

    public function store(StoreGameRequest $request): RedirectResponse
    {
        $game = $this->gameSetupService->createPendingGame(
            $request->user(),
            $request->createGameData(),
        );

        return redirect()->route('games.show', $game);
    }

    public function show(Game $game): Response
    {
        $game->load(['players', 'events']);

        return Inertia::render('games/Show', [
            'game' => $game->toData(),
            'canStart' => Gate::allows('start', $game),
        ]);
    }

    public function start(Game $game): RedirectResponse
    {
        Gate::authorize('start', $game);

        if ($game->status instanceof Finished) {
            return back()->with('error', 'Game has already finished.');
        }

        RunGame::dispatch($game);

        return back();
    }

    public function state(Game $game): JsonResponse
    {
        $game->load(['players', 'events']);

        return response()->json($game->toData());
    }

    protected function getAvailableProviders(): array
    {
        $allProviders = [
            [
                'id' => 'openai',
                'name' => 'OpenAI',
                'key' => config('ai.providers.openai.key'),
                'models' => [
                    ['id' => 'gpt-5.4', 'name' => 'GPT-5.4'],
                    ['id' => 'gpt-5.4-mini', 'name' => 'GPT-5.4 Mini'],
                    ['id' => 'gpt-5.4-nano', 'name' => 'GPT-5.4 Nano'],
                    ['id' => 'gpt-5.2', 'name' => 'GPT-5.2'],
                    ['id' => 'gpt-5-mini', 'name' => 'GPT-5 Mini'],
                    ['id' => 'gpt-5-nano', 'name' => 'GPT-5 Nano'],
                    ['id' => 'gpt-4o', 'name' => 'GPT-4o'],
                    ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini'],
                    ['id' => 'gpt-4.1', 'name' => 'GPT-4.1'],
                    ['id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 Mini'],
                    ['id' => 'gpt-4.1-nano', 'name' => 'GPT-4.1 Nano'],
                ],
            ],
            [
                'id' => 'anthropic',
                'name' => 'Anthropic',
                'key' => config('ai.providers.anthropic.key'),
                'models' => [
                    ['id' => 'claude-opus-4-7', 'name' => 'Claude Opus 4.7'],
                    ['id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6'],
                    ['id' => 'claude-opus-4-6', 'name' => 'Claude Opus 4.6'],
                    ['id' => 'claude-haiku-4-5-20251001', 'name' => 'Claude Haiku 4.5'],
                ],
            ],
            [
                'id' => 'gemini',
                'name' => 'Google Gemini',
                'key' => config('ai.providers.gemini.key'),
                'models' => [
                    ['id' => 'gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro'],
                    ['id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash'],
                    ['id' => 'gemini-2.5-flash-lite', 'name' => 'Gemini 2.5 Flash-Lite'],
                    ['id' => 'gemini-2.0-flash', 'name' => 'Gemini 2.0 Flash'],
                ],
            ],
        ];

        return collect($allProviders)
            ->filter(fn (array $provider) => ! empty($provider['key']))
            ->map(fn (array $provider) => collect($provider)->except('key')->all())
            ->values()
            ->all();
    }

    protected function getAvailablePersonalities(): array
    {
        return [
            'Aggressive and confrontational — quick to accuse others and push for elimination',
            'Calm and analytical — relies on logic and deduction, speaks methodically',
            'Nervous and paranoid — suspects everyone and frequently changes allegiances',
            'Charismatic and persuasive — skilled at rallying others to their cause',
            'Quiet and observant — speaks rarely but makes pointed, insightful observations',
            'Dramatic and emotional — reacts strongly to events and makes passionate speeches',
            'Deceptive and cunning — skilled at misdirection and planting false leads',
            'Trusting and cooperative — wants to build consensus and work as a team',
            'Sarcastic and dry-witted — deflects tension with humour and backhanded compliments',
            'Old-school sheriff — speaks in folksy wisdom, trusts gut instinct over hard evidence',
            'Conspiracy theorist — reads into everything, connects dots that may not exist',
            'Cold and calculating — treats the game like a chess match, emotionally detached',
            'Loyal protector — fixates on defending one player and becomes suspicious of their accusers',
            'Wildcard — unpredictable and chaotic, votes on vibes and changes strategy constantly',
            'People-pleaser — agrees with whoever spoke last, terrified of being the odd one out',
            'Veteran strategist — references past rounds constantly and builds long-running theories',
        ];
    }
}
