# AI Werewolf

Watch AI agents play **Werewolf** (Mafia) against each other. Laravel 12 orchestrates phases, roles, and win conditions; Laravel AI powers discussion, night actions, and votes; Inertia + Vue stream the spectator UI with optional TTS narration.

This is an intentional **god-view spectator** experience: player roles and AI `thinking` are visible so you can follow how models reason. It is not a hidden-information multiplayer client.

## Stack

- PHP 8.5 / Laravel 12 / Fortify / Wayfinder
- Inertia v2 + Vue 3 + TypeScript
- Spatie Data + TypeScript transformer, Spatie Model States
- Queued phase pipeline (`RunGame` / `RunCurrentPhase`)
- Soketi (Pusher-compatible) for realtime events
- Pest 4 (feature + browser tests)

## Requirements

- [Docker](https://docs.docker.com/get-docker/) + [Laravel Sail](https://laravel.com/docs/sail)
- At least one AI provider API key (OpenAI, Anthropic, Gemini, Groq, etc.)
- Optional: ElevenLabs (or configured TTS) for narration voices

## Setup

```bash
cp .env.example .env
vendor/bin/sail up -d
vendor/bin/sail composer install
vendor/bin/sail npm install
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate
vendor/bin/sail npm run build   # Wayfinder generates during Vite build
```

Set provider keys in `.env` (see comments in `.env.example`). Ensure `BROADCAST_CONNECTION` / Pusher vars match Soketi when using Sail.

Run the app + queue + Vite:

```bash
vendor/bin/sail composer run dev
```

Or open with `vendor/bin/sail open` after `sail up` and a queue worker.

## How a game runs

1. Sign in, create a game with 5–12 AI player slots (provider, model, personality).
2. Start the game — `RunGame` assigns roles/voices and kicks the phase queue.
3. Phases advance via step runners (Lobby → Night → Dawn → Day discussion → Day voting → Dusk → … → Game over).
4. The show page merges Echo events (with HTTP poll fallback) and can play narration audio.

## Architecture (short)

| Layer | Role |
|-------|------|
| `app/Roles` | Role rules (night actions, teams, elimination follow-ups) |
| `app/Services/GamePipeline` + `GameSteps` | Phase orchestration |
| `app/Services/*Resolver` | Night resolution, wins, elimination |
| `app/Ai` | Agents + shared game context for prompts |
| `app/States` | Spatie status/phase state machines |
| `app/Data` | Spatie DTOs (`#[TypeScript]` → `resources/js/types/generated.d.ts`) |

Wayfinder emits `@/actions` / `@/routes` at asset build time (those folders are gitignored). Spatie TS output is **committed** so deploy does not need `typescript:transform`.

Deploy uses a minimal [`railpack.json`](railpack.json) (Node 22 pin only). Do not add Wayfinder/Spatie/Playwright generate steps there.

## Testing

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail npm run typecheck
vendor/bin/sail npm run lint
```

Browser smoke tests (Pest browser plugin) live under `tests/Browser/` and are intended for CI/Sail, not the production image.

## License

MIT
