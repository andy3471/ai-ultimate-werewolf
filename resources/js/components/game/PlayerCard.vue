<script setup lang="ts">
import { computed } from 'vue';
import { roleMeta } from '@/features/games/roleMeta';
import RoleTooltip from './RoleTooltip.vue';

const props = defineProps<{
    player: {
        id: string;
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
    if (props.revealedRole) {
        return props.revealedRole;
    }
    if (props.player.role) {
        return props.player.role;
    }
    return null;
});

const roleDisplay = computed(() => {
    if (!displayRole.value) {
        return null;
    }

    const meta = roleMeta(displayRole.value);
    if (meta) {
        return {
            mark: meta.mark,
            label: meta.label,
            color: meta.teamColor,
        };
    }

    return {
        mark: '?',
        label: displayRole.value,
        color: 'text-slate-400',
    };
});

const providerRing = computed(() => {
    const map: Record<string, string> = {
        openai: 'ring-emerald-500/40',
        anthropic: 'ring-orange-500/40',
        gemini: 'ring-accent-blue/40',
    };
    return map[props.player.provider] || 'ring-white/15';
});

const isAlive = computed(() => props.player.is_alive && !props.isEliminated);
</script>

<template>
    <div
        :class="[
            'relative border px-3 py-3 transition-opacity duration-500',
            isAlive
                ? 'border-white/10 bg-dark-secondary/70'
                : 'border-white/5 bg-dark-primary/50 opacity-55',
        ]"
    >
        <div
            v-if="!isAlive"
            class="pointer-events-none absolute inset-0 flex items-center justify-end pr-3"
        >
            <span class="text-xs font-medium text-red-400/80">Eliminated</span>
        </div>

        <div class="flex items-center gap-3">
            <div
                :class="[
                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-md ring-1',
                    providerRing,
                    isAlive ? 'bg-dark-tertiary' : 'bg-dark-primary',
                ]"
            >
                <span class="text-sm font-semibold text-slate-200">{{ player.name.charAt(0) }}</span>
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold text-gray-100">{{ player.name }}</div>
                <RoleTooltip v-if="roleDisplay" :role="roleDisplay.label">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[10px] font-semibold text-slate-400" aria-hidden="true">{{ roleDisplay.mark }}</span>
                        <span :class="['text-xs font-medium', roleDisplay.color]">{{ roleDisplay.label }}</span>
                    </div>
                </RoleTooltip>
                <div v-else class="text-xs text-slate-500">Role hidden</div>
            </div>
        </div>
    </div>
</template>
