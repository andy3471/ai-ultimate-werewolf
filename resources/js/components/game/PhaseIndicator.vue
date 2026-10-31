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
    <div
        :class="[
            'rounded-xl bg-gradient-to-r p-4 transition-all duration-700',
            phaseDisplay.bg,
        ]"
    >
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-3xl">{{ phaseDisplay.icon }}</span>
                <div>
                    <h2 class="text-lg font-bold text-neutral-100">{{ phaseDisplay.label }}</h2>
                    <p v-if="narration" class="text-sm italic text-violet-300/80">{{ narration }}</p>
                    <p v-else class="text-sm text-neutral-400">{{ description }}</p>
                </div>
            </div>
            <div v-if="round > 0" class="text-right">
                <div class="text-2xl font-bold text-neutral-200">Round {{ round }}</div>
                <div :class="['text-xs font-medium', isNight ? 'text-indigo-400' : 'text-amber-400']">
                    {{ isNight ? '🌙 Night' : '☀️ Day' }}
                </div>
            </div>
        </div>
    </div>
</template>
