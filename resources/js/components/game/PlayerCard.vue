<script setup lang="ts">
import { computed } from 'vue';
import RoleTooltip from './RoleTooltip.vue';

const props = defineProps<{
    player: {
        id: number;
        name: string;
        provider: string;
        model: string;
        role: string | null;
        is_alive: boolean;
    };
    isEliminated?: boolean;
    revealedRole?: string | null;
}>();

const displayRole = computed(() => {
    if (props.revealedRole) return props.revealedRole;
    if (props.player.role) return props.player.role;
    return null;
});

const roleDisplay = computed(() => {
    const map: Record<string, { icon: string; label: string; color: string }> = {
        werewolf: { icon: '🐺', label: 'Werewolf', color: 'text-red-400' },
        villager: { icon: '🧑‍🌾', label: 'Villager', color: 'text-blue-400' },
        seer: { icon: '🔮', label: 'Seer', color: 'text-purple-400' },
        bodyguard: { icon: '🛡️', label: 'Bodyguard', color: 'text-emerald-400' },
        hunter: { icon: '🏹', label: 'Hunter', color: 'text-amber-400' },
        tanner: { icon: '🪚', label: 'Tanner', color: 'text-yellow-600' },
    };
    return displayRole.value ? map[displayRole.value] || { icon: '❓', label: displayRole.value, color: 'text-neutral-400' } : null;
});

const providerColor = computed(() => {
    const map: Record<string, string> = {
        openai: 'ring-green-600/40',
        anthropic: 'ring-orange-600/40',
        gemini: 'ring-blue-600/40',
    };
    return map[props.player.provider] || 'ring-neutral-600/40';
});

const isAlive = computed(() => props.player.is_alive && !props.isEliminated);
</script>

<template>
    <div
        :class="[
            'relative rounded-xl border p-3 transition-all duration-500',
            isAlive
                ? 'border-neutral-700 bg-neutral-900/80'
                : 'border-neutral-800/50 bg-neutral-950/50 opacity-60',
        ]"
    >
        <!-- Death overlay -->
        <div v-if="!isAlive" class="absolute inset-0 flex items-center justify-center rounded-xl bg-black/30">
            <span class="text-2xl">💀</span>
        </div>

        <div class="flex items-center gap-3">
            <div
                :class="[
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-full ring-2',
                    providerColor,
                    isAlive ? 'bg-neutral-800' : 'bg-neutral-900',
                ]"
            >
                <span class="text-sm font-bold text-neutral-300">{{ player.name.charAt(0) }}</span>
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold text-neutral-200">{{ player.name }}</div>
                <RoleTooltip v-if="roleDisplay" :role="roleDisplay.label">
                    <div class="flex items-center gap-1">
                        <span class="text-xs">{{ roleDisplay.icon }}</span>
                        <span :class="['text-xs font-medium', roleDisplay.color]">{{ roleDisplay.label }}</span>
                    </div>
                </RoleTooltip>
                <div v-else class="text-xs text-neutral-500">Role hidden</div>
            </div>
        </div>
    </div>
</template>
