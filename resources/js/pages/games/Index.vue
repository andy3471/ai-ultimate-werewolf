<script setup lang="ts">
import GameLayout from '@/layouts/GameLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { create, show } from '@/actions/App/Http/Controllers/GameController';

interface PlayerData {
    id: number;
    name: string;
    provider: string;
    model: string;
    role: string | null;
    is_alive: boolean;
}

interface GameData {
    id: number;
    status: string;
    phase: string;
    round: number;
    winner: string | null;
    players: PlayerData[];
    created_at: string;
}

defineProps<{
    games: GameData[];
}>();

function statusBadge(status: string) {
    switch (status) {
        case 'pending':
            return { label: 'Pending', class: 'bg-yellow-500/10 text-yellow-400 ring-yellow-500/20' };
        case 'running':
            return { label: 'Running', class: 'bg-green-500/10 text-green-400 ring-green-500/20' };
        case 'finished':
            return { label: 'Finished', class: 'bg-neutral-500/10 text-neutral-400 ring-neutral-500/20' };
        default:
            return { label: status, class: 'bg-neutral-500/10 text-neutral-400 ring-neutral-500/20' };
    }
}

function winnerLabel(winner: string | null) {
    if (!winner) return '';
    return winner === 'village' ? '🏘️ Village Wins' : '🐺 Werewolves Win';
}

function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <GameLayout>
        <Head title="Games" />

        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-neutral-100">AI Werewolf</h1>
                    <p class="mt-1 text-neutral-400">
                        Watch AI models play Werewolf against each other
                        <span class="mx-1 text-neutral-600">·</span>
                        <Link href="/rules" class="text-indigo-400 hover:text-indigo-300 transition">Learn the rules</Link>
                    </p>
                </div>
                <Link
                    :href="create.url()"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path
                            fill-rule="evenodd"
                            d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    New Game
                </Link>
            </div>

            <div v-if="games.length === 0" class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-12 text-center">
                <div class="mx-auto mb-4 text-5xl">🐺</div>
                <h3 class="text-lg font-semibold text-neutral-200">No games yet</h3>
                <p class="mt-1 text-neutral-400">Create your first game to watch AI models battle it out!</p>
                <Link
                    :href="create.url()"
                    class="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500"
                >
                    Create Game
                </Link>
            </div>

            <div v-else class="space-y-3">
                <Link
                    v-for="game in games"
                    :key="game.id"
                    :href="show.url(game.id)"
                    class="block rounded-xl border border-neutral-800 bg-neutral-900/50 p-5 transition hover:border-neutral-700 hover:bg-neutral-900"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="text-2xl">🐺</div>
                            <div>
                                <div class="flex items-center gap-3">
                                    <span class="font-semibold text-neutral-100">Game #{{ game.id }}</span>
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                            statusBadge(game.status).class,
                                        ]"
                                    >
                                        {{ statusBadge(game.status).label }}
                                    </span>
                                    <span v-if="game.winner" class="text-sm font-medium text-neutral-300">
                                        {{ winnerLabel(game.winner) }}
                                    </span>
                                </div>
                                <div class="mt-1 flex items-center gap-3 text-sm text-neutral-400">
                                    <span>{{ game.players.length }} players</span>
                                    <span v-if="game.round > 0">Round {{ game.round }}</span>
                                    <span>{{ formatDate(game.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex -space-x-2">
                            <div
                                v-for="player in game.players.slice(0, 6)"
                                :key="player.id"
                                class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-neutral-900 bg-neutral-800 text-xs font-medium text-neutral-300"
                                :title="player.name"
                            >
                                {{ player.name.charAt(0) }}
                            </div>
                            <div
                                v-if="game.players.length > 6"
                                class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-neutral-900 bg-neutral-700 text-xs font-medium text-neutral-300"
                            >
                                +{{ game.players.length - 6 }}
                            </div>
                        </div>
                    </div>
                </Link>
            </div>
        </div>
    </GameLayout>
</template>
