<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { create, show } from '@/actions/App/Http/Controllers/GameController';
import { winnerDisplay } from '@/features/games/winnerDisplay';
import GameLayout from '@/layouts/GameLayout.vue';
import { rules } from '@/routes';

type GameData = App.Data.GameData;

defineProps<{
    games: GameData[];
}>();

function statusBadge(status: string) {
    switch (status) {
        case 'pending':
            return { label: 'Pending', class: 'text-amber-300' };
        case 'running':
            return { label: 'Live', class: 'text-emerald-400' };
        case 'finished':
            return { label: 'Finished', class: 'text-slate-500' };
        default:
            return { label: status, class: 'text-slate-500' };
    }
}

function winnerLabel(winner: App.Enums.GameTeam | string | null) {
    const display = winnerDisplay(winner);

    return display ? display.shortLabel : '';
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

        <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="page-title">Games</h1>
                    <p class="mt-2 max-w-xl text-mist">
                        Lobbies and live tables.
                        <Link :href="rules.url()" class="link-quiet ml-1">Rules</Link>
                    </p>
                </div>
                <Link :href="create.url()" class="btn-brand-solid shrink-0 self-start sm:self-auto">
                    New game
                </Link>
            </div>

            <div
                v-if="games.length === 0"
                class="border border-dashed border-white/15 px-6 py-16 text-center"
            >
                <h2 class="text-lg font-semibold text-gray-100">No games yet</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-slate-400">
                    Set up a lobby, assign models, then watch them play.
                </p>
                <Link :href="create.url()" class="btn-brand-solid mt-6">
                    Create your first game
                </Link>
            </div>

            <ul v-else class="divide-y divide-white/10 border-y border-white/10" role="list">
                <li v-for="game in games" :key="game.id">
                    <Link
                        :href="show.url(game.id)"
                        class="flex items-center justify-between gap-4 py-4 transition hover:bg-white/[0.03]"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-gray-100">Game {{ game.reference }}</span>
                                <span class="text-xs font-medium" :class="statusBadge(game.status).class">
                                    {{ statusBadge(game.status).label }}
                                </span>
                                <span v-if="game.winner" class="text-sm text-slate-300">
                                    {{ winnerLabel(game.winner) }}
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-400">
                                <span>{{ game.players.length }} players</span>
                                <span v-if="game.round > 0">Round {{ game.round }}</span>
                                <span>{{ formatDate(game.created_at) }}</span>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </GameLayout>
</template>
