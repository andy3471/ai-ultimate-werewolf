<script setup lang="ts">
import ThoughtBubble from './ThoughtBubble.vue';
import { useAudioQueue } from '@/composables/useAudioQueue';
import { computed } from 'vue';

const { playOne } = useAudioQueue();

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
    audio_url?: string | null;
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
        narration: { icon: '📜', color: 'border-violet-800/50' },
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
        case 'narration':
            return msg ?? '';
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
    <div :class="['rounded-lg border-l-2 p-3', event.type === 'narration' ? 'bg-violet-950/30' : 'bg-neutral-900/50', typeConfig.color]">
        <div class="flex items-start gap-2">
            <span class="mt-0.5 text-base">{{ typeConfig.icon }}</span>
            <div class="min-w-0 flex-1">
                <div v-if="event.type === 'narration'" class="text-sm italic text-violet-300/90">
                    {{ displayText }}
                </div>
                <div v-else class="text-sm">
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
            <button
                v-if="event.audio_url"
                @click="playOne(event.audio_url!)"
                class="mt-0.5 flex-shrink-0 rounded-md p-1 text-neutral-500 transition hover:bg-neutral-800 hover:text-neutral-300"
                title="Play audio"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>
    </div>
</template>
