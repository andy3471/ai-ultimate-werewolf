<script setup lang="ts">
import { computed } from 'vue';
import { useAudioQueue } from '@/composables/useAudioQueue';
import ThoughtBubble from './ThoughtBubble.vue';

const { playOne } = useAudioQueue();

interface PlayerMap {
    [id: string]: { name: string; provider: string };
}

const props = defineProps<{
    event: App.Data.GameEventData;
    players: PlayerMap;
    forceShowThinking?: boolean;
}>();

const actorName = computed(() => {
    if (!props.event.actor_player_id) {
        return null;
    }
    return props.players[props.event.actor_player_id]?.name ?? `Player #${props.event.actor_player_id}`;
});

const targetName = computed(() => {
    if (!props.event.target_player_id) {
        return null;
    }
    return props.players[props.event.target_player_id]?.name ?? `Player #${props.event.target_player_id}`;
});

const addressedName = computed(() => {
    const addressedId = props.event.data?.addressed_player_id;
    if (typeof addressedId !== 'string' && typeof addressedId !== 'number') {
        return null;
    }

    const key = String(addressedId);

    return props.players[key]?.name ?? `Player #${key}`;
});

const typeConfig = computed(() => {
    const configs: Record<string, { label: string; color: string }> = {
        werewolf_kill: { label: 'Kill', color: 'border-red-500/40' },
        seer_investigate: { label: 'Seer', color: 'border-sky-500/40' },
        bodyguard_protect: { label: 'Guard', color: 'border-emerald-500/40' },
        discussion: { label: 'Talk', color: 'border-white/20' },
        dying_speech: { label: 'Last words', color: 'border-red-500/30' },
        nomination: { label: 'Nominate', color: 'border-amber-500/40' },
        nomination_result: { label: 'Trial', color: 'border-amber-500/40' },
        defense_speech: { label: 'Defense', color: 'border-yellow-500/40' },
        vote: { label: 'Vote', color: 'border-amber-500/40' },
        death: { label: 'Death', color: 'border-red-500/40' },
        elimination: { label: 'Eliminated', color: 'border-red-500/40' },
        bodyguard_save: { label: 'Saved', color: 'border-emerald-500/40' },
        hunter_shot: { label: 'Hunter', color: 'border-amber-500/40' },
        narration: { label: 'Narrator', color: 'border-white/25' },
        no_death: { label: 'Safe night', color: 'border-white/20' },
        vote_tally: { label: 'Tally', color: 'border-white/20' },
        vote_tie: { label: 'Tie', color: 'border-white/20' },
        no_elimination: { label: 'Spared', color: 'border-white/20' },
        game_end: { label: 'End', color: 'border-white/25' },
        error: { label: 'Error', color: 'border-red-500/50' },
    };
    return configs[props.event.type] || { label: props.event.type, color: 'border-white/15' };
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
            if (vote === 'yes') {
                return `voted to eliminate${props.event.public_reasoning ? `: "${props.event.public_reasoning}"` : ''}`;
            }
            if (vote === 'no') {
                return `voted to spare${props.event.public_reasoning ? `: "${props.event.public_reasoning}"` : ''}`;
            }
            return `voted to eliminate ${targetName.value}${props.event.public_reasoning ? `: "${props.event.public_reasoning}"` : ''}`;
        }
        case 'werewolf_kill':
            return `targeted ${targetName.value}`;
        case 'seer_investigate':
            return `investigated ${targetName.value}`;
        case 'bodyguard_protect':
            return `chose to protect ${targetName.value}`;
        case 'hunter_shot':
            return msg ?? `shot ${targetName.value} with their dying breath`;
        default:
            return msg ?? '';
    }
});
</script>

<template>
    <article
        :class="[
            'border-l-2 px-3 py-3',
            event.type === 'narration' ? 'bg-white/[0.03]' : 'bg-dark-secondary/40',
            typeConfig.color,
        ]"
    >
        <div class="flex items-start gap-3">
            <span class="mt-0.5 w-16 shrink-0 text-[11px] text-slate-500">
                {{ typeConfig.label }}
            </span>
            <div class="min-w-0 flex-1">
                <div v-if="event.type === 'narration'" class="text-sm italic text-slate-300">
                    {{ displayText }}
                </div>
                <div v-else class="text-sm">
                    <span v-if="actorName" class="font-semibold text-gray-100">{{ actorName }}</span>
                    <span v-if="addressedName && event.type === 'discussion'" class="text-slate-400"> → {{ addressedName }}</span>
                    <span v-if="actorName && displayText" class="text-slate-500">
                        {{ ['discussion', 'dying_speech', 'defense_speech'].includes(event.type) ? ':' : '' }}
                    </span>
                    <span class="text-slate-300">{{ displayText }}</span>
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
                type="button"
                class="mt-0.5 flex-shrink-0 rounded-md p-1 text-slate-500 transition hover:bg-white/5 hover:text-white"
                title="Play audio"
                @click="playOne(event.audio_url!)"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z" clip-rule="evenodd" />
                </svg>
                <span class="sr-only">Play audio</span>
            </button>
        </div>
    </article>
</template>
