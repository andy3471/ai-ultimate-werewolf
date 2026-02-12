<script setup lang="ts">
import { ref, computed } from 'vue';

const props = defineProps<{
    thinking: string;
    playerName?: string;
    forceExpanded?: boolean;
}>();

const manualExpanded = ref(false);
const expanded = computed(() => props.forceExpanded || manualExpanded.value);

function toggle() {
    manualExpanded.value = !manualExpanded.value;
}
</script>

<template>
    <div v-if="thinking" class="mt-2">
        <button
            @click="toggle()"
            class="flex items-center gap-1.5 text-xs text-neutral-500 transition hover:text-neutral-300"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                :class="['h-3.5 w-3.5 transition-transform', expanded ? 'rotate-90' : '']"
                viewBox="0 0 20 20"
                fill="currentColor"
            >
                <path
                    fill-rule="evenodd"
                    d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                    clip-rule="evenodd"
                />
            </svg>
            <span>💭 Show thinking</span>
        </button>
        <Transition
            enter-active-class="transition-all duration-300 ease-out"
            leave-active-class="transition-all duration-200 ease-in"
            enter-from-class="max-h-0 opacity-0"
            enter-to-class="max-h-96 opacity-100"
            leave-from-class="max-h-96 opacity-100"
            leave-to-class="max-h-0 opacity-0"
        >
            <div v-if="expanded" class="mt-2 overflow-hidden rounded-lg border border-neutral-800 bg-neutral-950/80 p-3">
                <div class="mb-1 text-xs font-medium text-neutral-500">
                    {{ playerName ? `${playerName}'s thinking` : 'Internal thinking' }}
                </div>
                <p class="whitespace-pre-wrap text-sm leading-relaxed text-neutral-400 italic">{{ thinking }}</p>
            </div>
        </Transition>
    </div>
</template>
