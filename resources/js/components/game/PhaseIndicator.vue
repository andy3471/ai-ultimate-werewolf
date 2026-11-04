<script setup lang="ts">
import { computed } from 'vue';
import { phaseMeta } from '@/features/games/phaseMeta';

const props = defineProps<{
    phase: string;
    round: number;
    description: string;
    narration?: string | null;
}>();

const phaseDisplay = computed(() => phaseMeta(props.phase));
const isNight = computed(() => props.phase === 'night');
</script>

<template>
    <div class="border border-white/10 bg-dark-secondary/40 px-4 py-4">
        <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-100">{{ phaseDisplay.label }}</h2>
                    <span class="text-xs text-mist">{{ phaseDisplay.mark }}</span>
                </div>
                <p v-if="narration" class="mt-1 text-sm italic text-slate-300">{{ narration }}</p>
                <p v-else class="mt-1 text-sm text-slate-400">{{ description }}</p>
            </div>
            <div v-if="round > 0" class="shrink-0 text-right">
                <div class="text-2xl font-semibold text-gray-100">Round {{ round }}</div>
                <div class="text-xs text-slate-500">
                    {{ isNight ? 'Night' : 'Day' }}
                </div>
            </div>
        </div>
    </div>
</template>
