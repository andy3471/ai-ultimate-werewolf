<script setup lang="ts">
import GameLogEntry from './GameLogEntry.vue';
import { ref, watch, nextTick } from 'vue';

interface GameEventData {
    id: number;
    round: number;
    phase: string;
    type: string;
    actor_player_id: number | null;
    target_player_id: number | null;
    message: string | null;
    thinking: string | null;
    public_reasoning: string | null;
    is_public: boolean;
    created_at: string;
}

interface PlayerMap {
    [id: number]: { name: string; provider: string };
}

const props = defineProps<{
    events: GameEventData[];
    players: PlayerMap;
}>();

const logContainer = ref<HTMLElement | null>(null);
const showAllThoughts = ref(false);

// Auto-scroll when new events arrive
watch(
    () => props.events.length,
    async () => {
        await nextTick();
        if (logContainer.value) {
            logContainer.value.scrollTop = logContainer.value.scrollHeight;
        }
    },
);

function phaseLabel(phase: string): string {
    const map: Record<string, string> = {
        night_werewolf: '🐺 Night - Werewolves',
        night_seer: '🔮 Night - Seer',
        night_bodyguard: '🛡️ Night - Bodyguard',
        dawn: '🌅 Dawn',
        day_discussion: '💬 Discussion',
        day_voting: '🗳️ Voting',
        dusk: '🌆 Dusk',
        game_over: '🏁 Game Over',
    };
    return map[phase] || phase;
}

// Group events by round and phase
function groupEvents(events: GameEventData[]) {
    const groups: { round: number; phase: string; label: string; events: GameEventData[] }[] = [];
    let currentKey = '';

    for (const event of events) {
        const key = `${event.round}-${event.phase}`;
        if (key !== currentKey) {
            currentKey = key;
            groups.push({
                round: event.round,
                phase: event.phase,
                label: phaseLabel(event.phase),
                events: [],
            });
        }
        groups[groups.length - 1].events.push(event);
    }

    return groups;
}
</script>

<template>
    <div class="flex flex-col rounded-xl border border-neutral-800 bg-neutral-950/50">
        <div class="flex items-center justify-between border-b border-neutral-800 px-4 py-3">
            <h3 class="text-sm font-semibold text-neutral-300">Game Log</h3>
            <button
                @click="showAllThoughts = !showAllThoughts"
                :class="[
                    'rounded-md px-2 py-1 text-xs font-medium transition',
                    showAllThoughts
                        ? 'bg-indigo-600/20 text-indigo-400'
                        : 'bg-neutral-800 text-neutral-400 hover:text-neutral-300',
                ]"
            >
                💭 {{ showAllThoughts ? 'Hide' : 'Show' }} All Thoughts
            </button>
        </div>

        <div ref="logContainer" class="flex-1 space-y-1 overflow-y-auto p-3" style="max-height: 600px">
            <div v-if="events.length === 0" class="py-12 text-center text-neutral-500">
                <div class="mb-2 text-3xl">🌙</div>
                <p>Waiting for the game to begin...</p>
            </div>

            <template v-for="group in groupEvents(events)" :key="`${group.round}-${group.phase}`">
                <div class="sticky top-0 z-10 -mx-3 bg-neutral-950/90 px-3 py-1.5 backdrop-blur-sm">
                    <div class="text-xs font-semibold text-neutral-500">
                        {{ group.label }}
                        <span v-if="group.round > 0" class="text-neutral-600">- Round {{ group.round }}</span>
                    </div>
                </div>
                <GameLogEntry
                    v-for="event in group.events"
                    :key="event.id"
                    :event="event"
                    :players="players"
                />
            </template>
        </div>
    </div>
</template>
