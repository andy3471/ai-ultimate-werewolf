<?php

namespace App\Http\Controllers;

use App\Data\CreateGameData;
use App\Jobs\RunGame;
use App\Models\Game;
use App\Models\Player;
use App\States\GamePhase\Lobby;
use App\States\GameStatus\Pending;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
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

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'players' => 'required|array|min:5|max:12',
            'players.*.name' => 'required|string|max:30',
            'players.*.provider' => 'required|string',
            'players.*.model' => 'required|string',
            'players.*.personality' => 'required|string',
        ]);

        $game = Game::create([
            'status' => Pending::getMorphClass(),
            'phase' => Lobby::getMorphClass(),
            'round' => 0,
        ]);

        foreach ($validated['players'] as $index => $playerData) {
            $game->players()->create([
                'name' => $playerData['name'],
                'provider' => $playerData['provider'],
                'model' => $playerData['model'],
                'personality' => $playerData['personality'],
                'order' => $index,
            ]);
        }

        return redirect()->route('games.show', $game);
    }

    public function show(Game $game): Response
    {
        $game->load(['players', 'events']);

        return Inertia::render('games/Show', [
            'game' => $game->toData(),
        ]);
    }

    public function start(Game $game): RedirectResponse
    {
        if ($game->status instanceof \App\States\GameStatus\Finished) {
            return back()->with('error', 'Game has already finished.');
        }

        RunGame::dispatch($game);

        return back();
    }

    /**
     * API endpoint: return fresh game state for polling fallback.
     */
    public function state(Game $game)
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
                    ['id' => 'claude-sonnet-4-20250514', 'name' => 'Claude Sonnet 4'],
                    ['id' => 'claude-haiku-4-5-20251001', 'name' => 'Claude Haiku 4.5'],
                ],
            ],
            [
                'id' => 'gemini',
                'name' => 'Google Gemini',
                'key' => config('ai.providers.gemini.key'),
                'models' => [
                    ['id' => 'gemini-2.0-flash', 'name' => 'Gemini 2.0 Flash'],
                    ['id' => 'gemini-2.5-pro-preview-06-05', 'name' => 'Gemini 2.5 Pro'],
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
        ];
    }
}
