<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    phase: string;
    round: number;
    description: string;
}>();

const phaseDisplay = computed(() => {
    const map: Record<string, { label: string; icon: string; bg: string }> = {
        lobby: { label: 'Lobby', icon: '🏠', bg: 'from-neutral-800 to-neutral-900' },
        night_werewolf: { label: 'Night - Werewolves', icon: '🐺', bg: 'from-indigo-950 to-neutral-950' },
        night_seer: { label: 'Night - Seer', icon: '🔮', bg: 'from-purple-950 to-neutral-950' },
        night_bodyguard: { label: 'Night - Bodyguard', icon: '🛡️', bg: 'from-emerald-950 to-neutral-950' },
        dawn: { label: 'Dawn', icon: '🌅', bg: 'from-amber-950 to-neutral-950' },
        day_discussion: { label: 'Discussion', icon: '💬', bg: 'from-sky-950 to-neutral-950' },
        day_voting: { label: 'Voting', icon: '🗳️', bg: 'from-red-950 to-neutral-950' },
        dusk: { label: 'Dusk', icon: '🌆', bg: 'from-orange-950 to-neutral-950' },
        game_over: { label: 'Game Over', icon: '🏁', bg: 'from-neutral-800 to-neutral-900' },
    };
    return map[props.phase] || { label: props.phase, icon: '❓', bg: 'from-neutral-800 to-neutral-900' };
});

const isNight = computed(() => props.phase.startsWith('night_'));
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
                    <p class="text-sm text-neutral-400">{{ description }}</p>
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
