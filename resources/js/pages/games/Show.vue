<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { index, start, state } from '@/actions/App/Http/Controllers/GameController';
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

const { enqueue: enqueueAudio, toggleMute, unlock: unlockAudio, muted } = useAudioQueue();

onMounted(() => {
    function handleFirstClick() {
        unlockAudio();
        document.removeEventListener('click', handleFirstClick);
    }
    document.addEventListener('click', handleFirstClick);
});

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

const phase = computed(() => currentPhase.value || props.game.phase);
const round = computed(() => currentRound.value || props.game.round);
const description = computed(() => phaseDescription.value || defaultPhaseDescription(phase.value));
const winner = computed(() => liveWinner.value || props.game.winner);
const winnerBanner = computed(() => (winner.value ? winnerDisplay(winner.value) : null));

const allEvents = computed(() => {
    const initial = props.game.events || [];
    const live = liveEvents.value || [];

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
            pollUntilStarted();
        },
    });
}

function pollUntilStarted(attempts = 0) {
    if (attempts > 15 || currentPhase.value) {
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

    return meta ? `${meta.bg} ring-1 ring-white/10` : 'bg-dark-tertiary';
}

function distributionIcon(role: string): string {
    return roleMeta(role)?.mark ?? '?';
}
</script>

<template>
    <GameLayout>
        <Head :title="`Game ${game.reference}`" />

        <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="index.url()" class="link-quiet text-sm">
                        ← All games
                    </Link>
                    <h1 class="page-title mt-2 text-2xl sm:text-3xl">Game {{ game.reference }}</h1>
                </div>
                <button
                    type="button"
                    class="rounded-lg border border-white/10 px-3 py-2 text-sm font-medium transition"
                    :class="muted
                        ? 'bg-dark-tertiary text-slate-500 hover:text-slate-300'
                        : 'border-white/20 bg-white/5 text-gray-100 hover:bg-white/10'"
                    :title="muted ? 'Unmute voices' : 'Mute voices'"
                    @click="toggleMute"
                >
                    {{ muted ? 'Muted' : 'Voices on' }}
                </button>
            </div>

            <div
                v-if="isFinished && winnerBanner"
                :class="['mb-6 border border-white/10 bg-gradient-to-r px-6 py-8 text-center', winnerBanner.color]"
            >
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Result</p>
                <h2 class="mt-2 text-3xl font-semibold text-gray-100">{{ winnerBanner.label }}</h2>
                <p v-if="winnerMessage" class="mt-2 text-slate-300">{{ winnerMessage }}</p>
            </div>

            <PhaseIndicator
                :phase="phase"
                :round="round"
                :description="description"
                :narration="liveNarration"
            />

            <div v-if="game.role_distribution" class="mt-4 border border-white/10 bg-dark-secondary/40 px-4 py-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Roles in play</span>
                    <RoleTooltip
                        v-for="(count, role) in game.role_distribution"
                        :key="role"
                        :role="String(role)"
                    >
                        <span :class="['inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-medium text-gray-100', distributionBadgeClass(String(role))]">
                            <span class="text-[10px] font-semibold text-slate-400" aria-hidden="true">{{ distributionIcon(String(role)) }}</span>
                            <span>{{ count }}× {{ role }}</span>
                        </span>
                    </RoleTooltip>
                </div>
            </div>

            <div v-if="isPending && !canStart" class="mt-8 text-center">
                <p class="text-sm text-slate-500">Waiting for the game creator to start the game…</p>
            </div>

            <div v-if="showStartButton" class="mt-8 text-center">
                <button
                    type="button"
                    class="btn-brand-solid px-8 py-3 text-lg disabled:cursor-not-allowed disabled:border-slate-600 disabled:bg-slate-700 disabled:text-slate-400"
                    :disabled="starting"
                    @click="startGame"
                >
                    <span v-if="starting" class="inline-flex items-center gap-2">
                        <svg class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                        Starting…
                    </span>
                    <span v-else>Start game</span>
                </button>
                <p class="mt-3 text-sm text-slate-500">
                    {{ game.players.length }} players ready. Roles are assigned at random.
                </p>
            </div>

            <div class="mt-8 grid gap-6 lg:grid-cols-[280px_1fr]">
                <aside class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-400">Players</h2>
                        <button
                            v-if="isRunning || isFinished"
                            type="button"
                            class="rounded-md px-2 py-1 text-xs font-medium transition"
                            :class="showAllRoles
                                ? 'bg-white/10 text-white'
                                : 'bg-dark-tertiary text-slate-400 hover:text-slate-200'"
                            @click="showAllRoles = !showAllRoles"
                        >
                            {{ showAllRoles ? 'Hide roles' : 'Reveal roles' }}
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
                </aside>

                <GameLog :events="allEvents" :players="playerMap" />
            </div>
        </div>
    </GameLayout>
</template>
