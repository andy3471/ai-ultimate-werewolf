<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { phaseLabel } from '@/features/games/phaseMeta';
import GameLogEntry from './GameLogEntry.vue';

interface PlayerMap {
    [id: string]: { name: string; provider: string };
}

const props = defineProps<{
    events: App.Data.GameEventData[];
    players: PlayerMap;
}>();

const logContainer = ref<HTMLElement | null>(null);
const showAllThoughts = ref(false);

watch(
    () => props.events.length,
    async () => {
        await nextTick();
        if (logContainer.value) {
            logContainer.value.scrollTop = logContainer.value.scrollHeight;
        }
    },
);

function groupEvents(events: App.Data.GameEventData[]) {
    const groups: {
        round: number;
        phase: string;
        label: string;
        events: App.Data.GameEventData[];
    }[] = [];
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
        groups[groups.length - 1]?.events.push(event);
    }

    return groups;
}
</script>

<template>
    <section class="flex flex-col border border-white/10 bg-dark-primary/40" aria-label="Game log">
        <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-300">Game log</h2>
            <button
                type="button"
                class="rounded-md px-2 py-1 text-xs font-medium transition"
                :class="showAllThoughts
                    ? 'bg-white/10 text-white'
                    : 'bg-dark-tertiary text-slate-400 hover:text-slate-200'"
                @click="showAllThoughts = !showAllThoughts"
            >
                {{ showAllThoughts ? 'Hide thoughts' : 'Show all thoughts' }}
            </button>
        </div>

        <div ref="logContainer" class="flex-1 space-y-1 overflow-y-auto p-3" style="max-height: 600px">
            <div v-if="events.length === 0" class="px-2 py-14 text-center text-slate-500">
                <p class="text-sm font-medium text-slate-400">Waiting for the game to begin</p>
                <p class="mt-1 text-sm">Events appear here as phases run.</p>
            </div>

            <template v-for="group in groupEvents(events)" :key="`${group.round}-${group.phase}`">
                <div class="sticky top-0 z-10 -mx-3 border-b border-white/5 bg-dark-primary px-3 py-1.5">
                    <div class="text-xs text-slate-500">
                        {{ group.label }}
                        <span v-if="group.round > 0" class="text-slate-600">, Round {{ group.round }}</span>
                    </div>
                </div>
                <GameLogEntry
                    v-for="event in group.events"
                    :key="event.id"
                    :event="event"
                    :players="players"
                    :force-show-thinking="showAllThoughts"
                />
            </template>
        </div>
    </section>
</template>
