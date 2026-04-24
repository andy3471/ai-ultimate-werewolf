# Game event contracts (frozen for refactors)

These `game_events.type` values and critical `data` / column keys are relied on by the game engine, step runners, Inertia/frontend, broadcasts, and `VoiceService`. Refactors must preserve them unless explicitly versioned.

## Consumers (non-role)

| Consumer | Depends on |
|----------|------------|
| `GameEngine` | `discussion`, `dawn_resolution`, `error` |
| `DawnStepRunner` | `death`, `bodyguard_save`, `no_death` (phase `dawn`) |
| `DayDiscussionStepRunner` | `discussion_warning_closing`, `discussion_warning_final_call` |
| `DayVotingStepRunner` | `nomination`, `nomination_result`, `nomination_second`, `defense_speech`, `vote`, `vote_outcome`, `dying_speech`, `hunter_shot`, `hunter_shot_followup_done`, `nomination_block` |
| `DayActionService` | `discussion_plan`, `discussion_pass`, `nomination_skip`, `nomination`, `nomination_result`, `nomination_second`, `defense_speech`, `vote`, `vote_tally`, `elimination`, `vote_outcome`, `no_elimination`, `discussion_extension` |
| `VoteResolver` | `vote_tally`, `no_elimination`, `vote_tie`, `elimination` |
| `EliminationService` | `dying_speech` |
| `WinConditionResolver` | `game_end` (`data.winner`, `data.message`) |
| `NarrationAudioService` | `narration` |
| `VoiceService` | Phase-based hints; `game_end` message; winner team string on `Game` |
| `RunCurrentPhase` job | `game_failed` |

## Event types (authoritative list)

- **Phases / meta:** `discussion`, `dawn_resolution`, `game_end`, `error`, `game_failed`, `narration`
- **Day discussion:** `discussion_plan`, `discussion_pass`, `nomination_skip`, `discussion_warning_closing`, `discussion_warning_final_call`, `discussion_extension`
- **Day voting / trial:** `nomination`, `nomination_result`, `nomination_second`, `defense_speech`, `vote`, `vote_tally`, `vote_outcome`, `elimination`, `no_elimination`, `vote_tie`, `nomination_block`
- **Elimination / hunter:** `dying_speech`, `hunter_shot`, `hunter_shot_followup_done`
- **Dawn:** `death` (`data.role_revealed`, `data.message`), `bodyguard_save`, `no_death`
- **Night (role-emitted, still contract):** `werewolf_kill`, `bodyguard_protect`, `seer_investigate`

## Critical payload keys

| Type | Keys / columns |
|------|----------------|
| `vote_outcome` | `data.eliminated_id` (nullable) |
| `vote_tally` | `data.message`, `data.yes`, `data.no` |
| `vote` | `data.vote` (`yes` / `no`), `actor_player_id`, `target_player_id` |
| `nomination_result` | `data.nominator_id`, `data.nomination_result_step` |
| `death` | `target_player_id`, `data.role_revealed`, `data.message` |
| `game_end` | `data.winner` (`village` \| `werewolves` \| `neutral`), `data.message` |
| `hunter_shot` | `actor_player_id`, `target_player_id`, `data.role_revealed`, `data.message` |
| `GameEnded` broadcast | winner string matches `GameTeam` enum values |

## External / UI

- Any client listening for `PlayerEliminated`, `PlayerActed`, `GameEnded` expects the above shapes.
- `GameContext` and AI prompts consume the public event stream; changing semantics breaks prompts without updating context builders.
