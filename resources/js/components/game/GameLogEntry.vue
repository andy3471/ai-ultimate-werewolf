<script setup lang="ts">
import ThoughtBubble from './ThoughtBubble.vue';
import { computed } from 'vue';

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
    data?: Record<string, any> | null;
}

interface PlayerMap {
    [id: number]: { name: string; provider: string };
}

const props = defineProps<{
    event: GameEventData;
    players: PlayerMap;
    forceShowThinking?: boolean;
}>();

const actorName = computed(() => {
    if (!props.event.actor_player_id) return null;
    return props.players[props.event.actor_player_id]?.name ?? `Player #${props.event.actor_player_id}`;
});

const targetName = computed(() => {
    if (!props.event.target_player_id) return null;
    return props.players[props.event.target_player_id]?.name ?? `Player #${props.event.target_player_id}`;
});

const addressedName = computed(() => {
    const addressedId = props.event.data?.addressed_player_id;
    if (!addressedId) return null;
    return props.players[addressedId]?.name ?? `Player #${addressedId}`;
});

const typeConfig = computed(() => {
    const configs: Record<string, { icon: string; color: string }> = {
        werewolf_kill: { icon: '🐺', color: 'border-red-900/50' },
        seer_investigate: { icon: '🔮', color: 'border-purple-900/50' },
        bodyguard_protect: { icon: '🛡️', color: 'border-emerald-900/50' },
        discussion: { icon: '💬', color: 'border-sky-900/50' },
        dying_speech: { icon: '💀', color: 'border-red-900/30' },
        nomination: { icon: '👉', color: 'border-amber-900/50' },
        nomination_result: { icon: '⚖️', color: 'border-amber-800/50' },
        defense_speech: { icon: '🗣️', color: 'border-yellow-900/50' },
        vote: { icon: '🗳️', color: 'border-amber-900/50' },
        death: { icon: '💀', color: 'border-red-800/50' },
        elimination: { icon: '⚖️', color: 'border-red-800/50' },
        bodyguard_save: { icon: '🛡️', color: 'border-emerald-800/50' },
        hunter_shot: { icon: '🏹', color: 'border-amber-800/50' },
        no_death: { icon: '☀️', color: 'border-amber-800/50' },
        vote_tally: { icon: '📊', color: 'border-neutral-700/50' },
        vote_tie: { icon: '🤝', color: 'border-neutral-700/50' },
        no_elimination: { icon: '✋', color: 'border-neutral-700/50' },
        game_end: { icon: '🏁', color: 'border-yellow-800/50' },
        error: { icon: '⚠️', color: 'border-red-700/50' },
    };
    return configs[props.event.type] || { icon: '📌', color: 'border-neutral-800/50' };
});

const displayText = computed(() => {
    const type = props.event.type;
    const msg = props.event.message;

    switch (type) {
        case 'discussion':
        case 'dying_speech':
        case 'defense_speech':
            return msg ?? '';
        case 'nomination':
            return `nominated ${targetName.value} for trial${props.event.public_reasoning ? `: "${props.event.public_reasoning}"` : ''}`;
        case 'vote': {
            const vote = props.event.data?.vote;
            if (vote === 'yes') return `voted to ELIMINATE${props.event.public_reasoning ? `: "${props.event.public_reasoning}"` : ''}`;
            if (vote === 'no') return `voted to SPARE${props.event.public_reasoning ? `: "${props.event.public_reasoning}"` : ''}`;
            return `voted to eliminate ${targetName.value}${props.event.public_reasoning ? `: "${props.event.public_reasoning}"` : ''}`;
        }
        case 'werewolf_kill':
            return `targeted ${targetName.value}`;
        case 'seer_investigate':
            return `investigated ${targetName.value}`;
        case 'bodyguard_protect':
            return `chose to protect ${targetName.value}`;
        case 'hunter_shot':
            return msg ?? `shot ${targetName.value} with their dying breath!`;
        default:
            return msg ?? '';
    }
});
</script>

<template>
    <div :class="['rounded-lg border-l-2 bg-neutral-900/50 p-3', typeConfig.color]">
        <div class="flex items-start gap-2">
            <span class="mt-0.5 text-base">{{ typeConfig.icon }}</span>
            <div class="min-w-0 flex-1">
                <div class="text-sm">
                    <span v-if="actorName" class="font-semibold text-neutral-200">{{ actorName }}</span>
                    <span v-if="addressedName && event.type === 'discussion'" class="text-sky-400/80"> → {{ addressedName }}</span>
                    <span v-if="actorName && displayText" class="text-neutral-400"> {{ ['discussion', 'dying_speech', 'defense_speech'].includes(event.type) ? ':' : '' }} </span>
                    <span class="text-neutral-300">{{ displayText }}</span>
                </div>

                <ThoughtBubble
                    v-if="event.thinking"
                    :thinking="event.thinking"
                    :player-name="actorName ?? undefined"
                    :force-expanded="forceShowThinking"
                />
            </div>
        </div>
    </div>
</template>
