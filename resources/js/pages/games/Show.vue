<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { start, state } from '@/actions/App/Http/Controllers/GameController';
import GameLog from '@/components/game/GameLog.vue';
import PhaseIndicator from '@/components/game/PhaseIndicator.vue';
import PlayerCard from '@/components/game/PlayerCard.vue';
import RoleTooltip from '@/components/game/RoleTooltip.vue';
import { useAudioQueue } from '@/composables/useAudioQueue';
import { useGameChannel } from '@/composables/useGameChannel';
import { phaseDescription as defaultPhaseDescription } from '@/features/games/phaseMeta';
import { roleMeta } from '@/features/games/roleMeta';
import { winnerDisplay } from '@/features/games/winnerDisplay';
import GameLayout from '@/layouts/GameLayout.vue';

type GameData = App.Data.GameData;
type PlayerData = App.Data.PlayerData;

const props = defineProps<{
    game: GameData;
    canStart: boolean;
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
const description = computed(() => phaseDescription.value || defaultPhaseDescription(phase.value));
const winner = computed(() => liveWinner.value || props.game.winner);
const winnerBanner = computed(() => (winner.value ? winnerDisplay(winner.value) : null));

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
    const map: Record<string, { name: string; provider: string }> = {};
    for (const player of props.game.players) {
        map[player.id] = { name: player.name, provider: player.provider };
    }
    return map;
});

const isPending = computed(() => props.game.status === 'pending' && !currentPhase.value && !starting.value);
const showStartButton = computed(() => isPending.value && props.canStart);
const isRunning = computed(() => props.game.status === 'running' || !!currentPhase.value || starting.value);
const isFinished = computed(() => !!winner.value);

function startGame() {
    starting.value = true;
    router.post(start.url(props.game.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            // The queued job may start before or after the Inertia redirect.
            // Poll the API briefly to detect when the game has started,
            // then reload the page via Inertia to get fresh props.
            pollUntilStarted();
        },
    });
}

function pollUntilStarted(attempts = 0) {
    if (attempts > 15 || currentPhase.value) {
        // Either the WebSocket already delivered the phase change, or we've waited long enough
        if (!currentPhase.value && attempts > 15) {
            starting.value = false;
        }
        return;
    }

    setTimeout(async () => {
        try {
            const res = await fetch(state.url(props.game.id));
            const data = (await res.json()) as App.Data.GameData;
            if (data.status !== 'pending') {
                router.reload();
                return;
            }
        } catch {
            // ignore fetch errors
        }
        pollUntilStarted(attempts + 1);
    }, 1000);
}

function displayPlayer(player: PlayerData) {
    if (!showAllRoles.value && player.is_alive && !eliminatedPlayerIds.value.has(player.id)) {
        return { ...player, role: null };
    }
    return player;
}

function distributionBadgeClass(role: string): string {
    const meta = roleMeta(role);

    return meta ? `${meta.bg} ring-1 ring-neutral-700/40` : 'bg-neutral-800';
}

function distributionIcon(role: string): string {
    return roleMeta(role)?.icon ?? '❓';
}
</script>

<template>
    <GameLayout>
        <Head :title="`Game #${game.id}`" />

        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <!-- Winner Banner -->
            <div
                v-if="isFinished && winnerBanner"
                :class="[
                    'mb-6 rounded-xl bg-gradient-to-r p-6 text-center',
                    winnerBanner.color,
                ]"
            >
                <div class="text-5xl">{{ winnerBanner.icon }}</div>
                <h2 class="mt-2 text-2xl font-bold text-neutral-100">{{ winnerBanner.label }}</h2>
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
                    <RoleTooltip
                        v-for="(count, role) in game.role_distribution"
                        :key="role"
                        :role="String(role)"
                    >
                        <span :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium text-neutral-200', distributionBadgeClass(String(role))]">
                            <span>{{ distributionIcon(String(role)) }}</span>
                            <span>{{ count }}x {{ role }}</span>
                        </span>
                    </RoleTooltip>
                </div>
            </div>

            <!-- Pending notice for non-owners -->
            <div v-if="isPending && !canStart" class="mt-6 text-center">
                <p class="text-sm text-neutral-500">Waiting for the game creator to start the game...</p>
            </div>

            <!-- Start Button -->
            <div v-if="showStartButton" class="mt-6 text-center">
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
