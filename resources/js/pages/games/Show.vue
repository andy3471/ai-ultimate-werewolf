<script setup lang="ts">
import GameLayout from '@/layouts/GameLayout.vue';
import GameLog from '@/components/game/GameLog.vue';
import PhaseIndicator from '@/components/game/PhaseIndicator.vue';
import PlayerCard from '@/components/game/PlayerCard.vue';
import { useGameChannel } from '@/composables/useGameChannel';
import { useAudioQueue } from '@/composables/useAudioQueue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, onMounted } from 'vue';
import { start } from '@/actions/App/Http/Controllers/GameController';

interface PlayerData {
    id: number;
    name: string;
    provider: string;
    model: string;
    role: string | null;
    is_alive: boolean;
    personality: string;
    order: number;
}

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
}

interface GameData {
    id: number;
    status: string;
    phase: string;
    round: number;
    winner: string | null;
    role_distribution: Record<string, number> | null;
    players: PlayerData[];
    events: GameEventData[];
    created_at: string;
}

const props = defineProps<{
    game: GameData;
}>();

const starting = ref(false);
const showAllRoles = ref(false);

// Audio playback — unlock on first user click so autoplay policy allows audio
const { enqueue: enqueueAudio, toggleMute, unlock: unlockAudio, muted } = useAudioQueue();

onMounted(() => {
    function handleFirstClick() {
        unlockAudio();
        document.removeEventListener('click', handleFirstClick);
    }
    document.addEventListener('click', handleFirstClick);
});

// Real-time updates via Echo (auto-enqueue audio for new events)
const {
    currentPhase,
    currentRound,
    phaseDescription,
    narration: liveNarration,
    events: liveEvents,
    eliminatedPlayerIds,
    revealedRoles,
    winner: liveWinner,
    winnerMessage,
} = useGameChannel(props.game.id, {
    onEvent(event) {
        if (event.audio_url) {
            enqueueAudio(event.audio_url);
        }
    },
    onPhaseChanged(data) {
        if (data.narration_audio_url) {
            enqueueAudio(data.narration_audio_url);
        }
    },
});

// Merged phase: live data takes priority over initial props
const phase = computed(() => currentPhase.value || props.game.phase);
const round = computed(() => currentRound.value || props.game.round);
const description = computed(() => phaseDescription.value || phaseLabel(phase.value));
const winner = computed(() => liveWinner.value || props.game.winner);

// Merge initial events with live events
const allEvents = computed(() => {
    const initial = props.game.events || [];
    const live = liveEvents.value || [];

    // Deduplicate by id
    const seen = new Set(initial.map((e) => e.id));
    const merged = [...initial];
    for (const event of live) {
        if (!seen.has(event.id)) {
            merged.push(event);
            seen.add(event.id);
        }
    }

    return merged;
});

// Player map for the log component
const playerMap = computed(() => {
    const map: Record<number, { name: string; provider: string }> = {};
    for (const player of props.game.players) {
        map[player.id] = { name: player.name, provider: player.provider };
    }
    return map;
});

const isPending = computed(() => props.game.status === 'pending' && !currentPhase.value);
const isRunning = computed(() => props.game.status === 'running' || !!currentPhase.value);
const isFinished = computed(() => !!winner.value);

function startGame() {
    starting.value = true;
    router.post(start.url(props.game.id), {}, {
        preserveScroll: true,
        onFinish: () => { starting.value = false; },
    });
}

function phaseLabel(p: string): string {
    const map: Record<string, string> = {
        lobby: 'Waiting for game to start...',
        night_werewolf: 'The werewolves choose their victim.',
        night_seer: 'The Seer investigates a player.',
        night_bodyguard: 'The Bodyguard chooses a player to protect.',
        dawn: 'Night actions are resolved.',
        day_discussion: 'Players discuss and share suspicions.',
        day_voting: 'Players vote to eliminate a suspect.',
        dusk: 'The vote is resolved.',
        game_over: 'The game has ended.',
    };
    return map[p] || '';
}

function winnerDisplay(w: string) {
    if (w === 'village') return { label: 'The Village Wins!', icon: '🏘️', color: 'from-sky-900/50 to-neutral-950' };
    if (w === 'neutral') return { label: 'The Tanner Wins!', icon: '🪚', color: 'from-yellow-900/50 to-neutral-950' };
    return { label: 'The Werewolves Win!', icon: '🐺', color: 'from-red-900/50 to-neutral-950' };
}

function displayPlayer(player: PlayerData) {
    // If showAllRoles is off, hide role for alive players (dead roles are always shown)
    if (!showAllRoles.value && player.is_alive && !eliminatedPlayerIds.value.has(player.id)) {
        return { ...player, role: null };
    }
    return player;
}

const roleIcons: Record<string, string> = {
    Werewolf: '🐺',
    Villager: '🧑‍🌾',
    Seer: '🔮',
    Bodyguard: '🛡️',
    Hunter: '🏹',
    Tanner: '🪚',
};
</script>

<template>
    <GameLayout>
        <Head :title="`Game #${game.id}`" />

        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <!-- Winner Banner -->
            <div
                v-if="isFinished && winner"
                :class="[
                    'mb-6 rounded-xl bg-gradient-to-r p-6 text-center',
                    winnerDisplay(winner).color,
                ]"
            >
                <div class="text-5xl">{{ winnerDisplay(winner).icon }}</div>
                <h2 class="mt-2 text-2xl font-bold text-neutral-100">{{ winnerDisplay(winner).label }}</h2>
                <p v-if="winnerMessage" class="mt-1 text-neutral-300">{{ winnerMessage }}</p>
            </div>

            <!-- Phase Indicator + Audio Toggle -->
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <PhaseIndicator :phase="phase" :round="round" :description="description" :narration="liveNarration" />
                </div>
                <button
                    @click="toggleMute"
                    :class="[
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        muted
                            ? 'bg-neutral-800 text-neutral-500 hover:text-neutral-300'
                            : 'bg-indigo-600/20 text-indigo-400 hover:bg-indigo-600/30',
                    ]"
                    :title="muted ? 'Unmute voices' : 'Mute voices'"
                >
                    <span v-if="muted">🔇 Muted</span>
                    <span v-else>🔊 Voices</span>
                </button>
            </div>

            <!-- Role Distribution (always visible to observer) -->
            <div v-if="game.role_distribution" class="mt-4 rounded-lg border border-neutral-800/50 bg-neutral-900/50 px-4 py-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-xs font-semibold uppercase tracking-wider text-neutral-500">Roles in play:</span>
                    <span
                        v-for="(count, role) in game.role_distribution"
                        :key="role"
                        class="inline-flex items-center gap-1 rounded-full bg-neutral-800 px-2.5 py-0.5 text-xs font-medium text-neutral-300"
                    >
                        <span>{{ roleIcons[role] || '❓' }}</span>
                        <span>{{ count }}x {{ role }}</span>
                    </span>
                </div>
            </div>

            <!-- Start Button -->
            <div v-if="isPending" class="mt-6 text-center">
                <button
                    @click="startGame"
                    :disabled="starting"
                    :class="[
                        'inline-flex items-center gap-3 rounded-xl px-8 py-4 text-lg font-bold text-white shadow-lg transition-all',
                        starting
                            ? 'cursor-not-allowed bg-neutral-700'
                            : 'bg-indigo-600 hover:bg-indigo-500 hover:shadow-indigo-500/25',
                    ]"
                >
                    <span v-if="starting" class="flex items-center gap-2">
                        <svg class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Starting...
                    </span>
                    <span v-else>🐺 Start Game</span>
                </button>
                <p class="mt-2 text-sm text-neutral-500">
                    {{ game.players.length }} players ready.
                    Roles will be randomly assigned.
                </p>
            </div>

            <!-- Main Layout: Players + Log -->
            <div class="mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
                <!-- Player Grid -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-neutral-400">Players</h3>
                        <button
                            v-if="isRunning || isFinished"
                            @click="showAllRoles = !showAllRoles"
                            :class="[
                                'rounded-md px-2 py-1 text-xs font-medium transition',
                                showAllRoles
                                    ? 'bg-indigo-600/20 text-indigo-400'
                                    : 'bg-neutral-800 text-neutral-400 hover:text-neutral-300',
                            ]"
                        >
                            👁 {{ showAllRoles ? 'Hide' : 'Reveal' }} Roles
                        </button>
                    </div>
                    <div class="space-y-2">
                        <PlayerCard
                            v-for="player in game.players"
                            :key="player.id"
                            :player="displayPlayer(player)"
                            :is-eliminated="eliminatedPlayerIds.has(player.id)"
                            :revealed-role="revealedRoles.get(player.id) ?? null"
                        />
                    </div>
                </div>

                <!-- Game Log -->
                <GameLog :events="allEvents" :players="playerMap" />
            </div>
        </div>
    </GameLayout>
</template>
