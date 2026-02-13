<script setup lang="ts">
import { computed, ref } from 'vue';

const props = defineProps<{
    role: string;
}>();

const show = ref(false);

const roleInfo: Record<string, { icon: string; team: string; teamColor: string; short: string }> = {
    Werewolf: {
        icon: '🐺',
        team: 'Werewolf',
        teamColor: 'text-red-400',
        short: 'Wakes each night to choose a victim with the pack. On Night 1, werewolves only learn each other\'s identities.',
    },
    werewolf: {
        icon: '🐺',
        team: 'Werewolf',
        teamColor: 'text-red-400',
        short: 'Wakes each night to choose a victim with the pack. On Night 1, werewolves only learn each other\'s identities.',
    },
    Seer: {
        icon: '🔮',
        team: 'Village',
        teamColor: 'text-purple-400',
        short: 'Investigates one player each night to learn if they are a werewolf.',
    },
    seer: {
        icon: '🔮',
        team: 'Village',
        teamColor: 'text-purple-400',
        short: 'Investigates one player each night to learn if they are a werewolf.',
    },
    Bodyguard: {
        icon: '🛡️',
        team: 'Village',
        teamColor: 'text-emerald-400',
        short: 'Protects one player each night from werewolf attack. Cannot guard the same player twice in a row.',
    },
    bodyguard: {
        icon: '🛡️',
        team: 'Village',
        teamColor: 'text-emerald-400',
        short: 'Protects one player each night from werewolf attack. Cannot guard the same player twice in a row.',
    },
    Hunter: {
        icon: '🏹',
        team: 'Village',
        teamColor: 'text-amber-400',
        short: 'When eliminated (day or night), takes one other player down with them.',
    },
    hunter: {
        icon: '🏹',
        team: 'Village',
        teamColor: 'text-amber-400',
        short: 'When eliminated (day or night), takes one other player down with them.',
    },
    Villager: {
        icon: '🧑‍🌾',
        team: 'Village',
        teamColor: 'text-blue-400',
        short: 'No special ability. Uses discussion and logic to find the werewolves.',
    },
    villager: {
        icon: '🧑‍🌾',
        team: 'Village',
        teamColor: 'text-blue-400',
        short: 'No special ability. Uses discussion and logic to find the werewolves.',
    },
    Tanner: {
        icon: '🪚',
        team: 'Neutral',
        teamColor: 'text-yellow-500',
        short: 'Wins alone if eliminated by the village during the day vote. Tries to act suspicious.',
    },
    tanner: {
        icon: '🪚',
        team: 'Neutral',
        teamColor: 'text-yellow-500',
        short: 'Wins alone if eliminated by the village during the day vote. Tries to act suspicious.',
    },
};

const info = computed(() => roleInfo[props.role] || null);
</script>

<template>
    <span
        class="relative inline-flex cursor-help"
        @mouseenter="show = true"
        @mouseleave="show = false"
    >
        <slot />
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="show && info"
                class="absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded-lg border border-neutral-700 bg-neutral-800 p-3 shadow-xl"
            >
                <div class="flex items-center gap-2">
                    <span class="text-lg">{{ info.icon }}</span>
                    <span class="font-semibold text-neutral-100">{{ role }}</span>
                    <span :class="['ml-auto text-[10px] font-bold uppercase tracking-wider', info.teamColor]">{{ info.team }}</span>
                </div>
                <p class="mt-1.5 text-xs leading-relaxed text-neutral-400">{{ info.short }}</p>
                <!-- Arrow -->
                <div class="absolute left-1/2 top-full -translate-x-1/2">
                    <div class="h-0 w-0 border-x-[6px] border-t-[6px] border-x-transparent border-t-neutral-700"></div>
                </div>
            </div>
        </Transition>
    </span>
</template>
