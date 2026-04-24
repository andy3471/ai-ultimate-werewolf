---
name: Role logic consolidation
overview: Move remaining role-specific rules out of services/runners into Role classes (or thin domain services composed from roles), keep orchestration explicit, and add tests so behavior stays identical.
todos:
  - id: inventory-freeze-contracts
    content: Freeze public event contracts (types, payload keys) and list every non-role caller that depends on them; document in-repo as checklist for refactors.
    status: pending
  - id: wincondition-tanner-teams
    content: Refactor WinConditionResolver — delegate Tanner village-elimination win to Tanner role hook; replace hardcoded werewolf vs non-werewolf counts with team aggregation via RoleRegistry (Werewolves vs Village vs Neutral).
    status: pending
  - id: night-order-from-registry
    content: Replace NightRoleStepRunner hardcoded [Werewolf, Seer, Bodyguard] with registry-driven ordering (e.g. Role::nightResolutionOrder() or ordered list from roles that haveNightAction()).
    status: pending
  - id: nightresolver-decompose
    content: Split NightResolver dawn kill/save resolution — move kill/protect source queries to Werewolf/Bodyguard (or small collaborators), keep a single composer that applies interaction rules and emits same dawn events.
    status: pending
  - id: pipeline-hunter-markers
    content: Generalize DayVotingStepRunner + DawnStepRunner hunter_shot / hunter_shot_followup_done sequencing — either role-declared follow-up steps after onElimination, or a tiny EliminationPipeline value object driven by events (no GameRole::Hunter branch in runner).
    status: pending
  - id: voice-narration-hints
    content: Optional — extract narrator strings for role-tinted outcomes (bodyguard save, hunter shot, tanner win) to Role::narrationContext() fragments or a NarrationHint registry to reduce VoiceService string coupling.
    status: pending
  - id: gamesetup-deck-metadata
    content: Optional — express GameSetupService deck composition via role metadata (required roles, counts by player count) instead of a hardcoded PHP array.
    status: pending
  - id: tests-per-phase
    content: Add/update feature tests for win resolution (Tanner, village, wolves), dawn resolution (save/kill/none), and night order; run minimal suites after each phase.
    status: pending
isProject: false
---

# Role logic consolidation (tidy-up plan)

## Goal

Reduce **role-specific branching** in generic services (`WinConditionResolver`, `NightResolver`, `VoiceService`, step runners) by pushing rules onto **`Role` implementations** (or **small domain services composed from `RoleRegistry`**), while keeping **orchestration** (queue steps, phase transitions, event ordering) explicit and testable.

## Principles

1. **Roles own rules** — win quirks, night contributions, elimination follow-ups, and “what events mean” for that role live on or behind the role class.
2. **Orchestrators own sequencing** — `*StepRunner`, jobs, and `GameEngine` coordinate *when* things run, not *what Werewolf means*.
3. **Stable event contracts** — existing `game_events.type` and `data` keys stay backward-compatible unless versioned; refactors preserve replay and frontend expectations.
4. **Prove with tests** — each phase lands with targeted Pest coverage; no large refactor without a green slice.

## Current hotspots (from audit)

| Area | Issue | Target shape |
|------|--------|----------------|
| `WinConditionResolver` | Tanner special-case + `=== GameRole::Werewolf` counting | Role hook or team-based aggregation via `Role::team()` |
| `NightRoleStepRunner` | Hardcoded night role order | Registry + explicit ordering API on `Role` |
| `NightResolver` | Knows `werewolf_kill` / `bodyguard_protect` interaction | Werewolf/Bodyguard supply “proposals”; one composer applies rules |
| `DayVotingStepRunner` / `DawnStepRunner` | Hunter-specific `hunter_shot` pipeline markers | Generic “post-elimination follow-up” contract or event-driven pipeline |
| `VoiceService` | Narration copy tied to event types / winner strings | Optional `Role`/`NarrationHint` snippets |
| `GameSetupService` | Hardcoded deck list | Optional metadata-driven deck builder |

## Phased approach

### Phase A — Contracts and safety net (small)

- Write a short **event contract checklist** (types + critical `data` keys) used by `GameContext`, UI, and `VoiceService`.
- Add **one regression test file** (or extend existing) that snapshots “happy path” sequences: night → dawn (no death / death / save), day voting elimination, game end village/wolves.

**Exit:** checklist merged; baseline tests green.

### Phase B — Win conditions (high value, bounded)

- Add a `Role` method such as `onVillageEliminationWinCheck(Game $game, Player $eliminated): ?GameTeam` (default `null`) and implement **Tanner** there (return `Neutral` when eliminated by village vote path — align with how `eliminatedByVillage` is passed today).
- Refactor counting: `alivePlayers` → map each to `RoleRegistry::get($role)->team()` → aggregate `Werewolves` vs `Village` vs `Neutral` instead of `!== Werewolf`.

**Exit:** `WinConditionResolver` contains no `GameRole::Tanner` branch; parity logic uses teams; tests for Tanner win + normal wins.

### Phase C — Night order (small, low risk)

- Add `Role::nightPhaseOrder(): int` (or static ordered list) on Werewolf, Seer, Bodyguard; default `0` or “not in night resolution order” for others.
- `NightRoleStepRunner` builds ordered role list by sorting registered night actors.

**Exit:** no hardcoded `[Werewolf, Seer, Bodyguard]` array in runner; order tests if order is non-obvious.

### Phase D — `NightResolver` (medium, highest coupling)

- Introduce narrow interfaces on roles, e.g. `Werewolf::nightKillTargetEvent(Game $game): ?GameEvent` / query helper, `Bodyguard::nightProtectionTargetEvent(...)`, **or** a `NightProposalReader` service used by roles.
- Keep **one** `NightResolver::resolve()` (or rename) that: loads proposals, applies “protect cancels kill on same target”, emits same `death` / `bodyguard_save` / `no_death` events.

**Exit:** resolver does not reference role enum; only event types / role classes; dawn tests unchanged in behavior.

### Phase E — Elimination pipeline markers (medium)

- After `Hunter::onElimination` is canonical, replace `GameRole::Hunter` checks in `DayVotingStepRunner` with either:
  - **Event-based:** “if latest elimination produced a `hunter_shot` needing dying speech, run step” (already partially there), or
  - **Role API:** `Role::eliminationFollowUpSteps(): array` consumed by a tiny state machine.

**Exit:** runners do not reference `GameRole::Hunter`; hunter dusk/dawn flows covered by tests.

### Phase F — Optional polish

- **VoiceService:** role-provided narration fragments for special cases.
- **GameSetupService:** deck from role metadata.

## Testing strategy

- After **each phase**: `vendor/bin/sail artisan test --compact` on affected files (`WinCondition*`, `Night*`, `RolePipeline*`, `GameQueueFlow*`, `DayVotingFlow*`).
- Prefer **event assertions** (types, `target_player_id`, `data` keys) over brittle full log dumps.

## Risks

- **Silent behavior drift** if event payloads change — mitigate with contract checklist + assertions.
- **Over-abstraction** — stop at “resolver composed from roles”; avoid duplicating full game engine inside each role.

## Suggested order of execution

B → C → E → D → F (A runs first in parallel with any phase).

## Definition of done

- No `GameRole::` switches in `WinConditionResolver` / `NightResolver` / `NightRoleStepRunner` for rules that belong to a single role.
- Hunters / Tanner / night resolution covered by tests.
- `AGENTS.md` / contributor note optional: “role rules live in `app/Roles`”.
