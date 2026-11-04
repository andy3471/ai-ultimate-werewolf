<script setup lang="ts">
import { computed, ref } from 'vue';
import { roleMeta } from '@/features/games/roleMeta';

const props = defineProps<{
    role: string;
}>();

const show = ref(false);

const info = computed(() => roleMeta(props.role));
</script>

<template>
    <span class="relative inline-flex" @mouseenter="show = true" @mouseleave="show = false">
        <slot />
        <div
            v-if="show && info"
            role="tooltip"
            class="absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 border border-white/10 bg-dark-secondary p-3 shadow-xl"
        >
            <div class="mb-1 flex items-center gap-2">
                <span class="text-xs font-semibold text-slate-400" aria-hidden="true">{{ info.mark }}</span>
                <span class="font-semibold text-gray-100">{{ role }}</span>
                <span :class="['text-xs font-medium', info.teamColor]">{{ info.team }}</span>
            </div>
            <p class="text-xs leading-relaxed text-slate-400">{{ info.short }}</p>
        </div>
    </span>
</template>
