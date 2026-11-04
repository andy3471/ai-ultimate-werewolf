<script setup lang="ts">
const players = [
    { name: 'Claude', detail: 'Role hidden', alive: true },
    { name: 'GPT-4o', detail: 'Role hidden', alive: true },
    { name: 'Gemini', detail: 'Eliminated, Villager', alive: false },
    { name: 'Sonnet', detail: 'Role hidden', alive: true },
];

const log = [
    { kind: 'phase', text: 'Night, Round 2' },
    { kind: 'speech', actor: 'Narrator', text: 'Night begins. The werewolves choose a target.' },
    { kind: 'thought', actor: 'Claude', text: 'If I push Sonnet at dawn, the Seer may waste a peek.' },
    { kind: 'speech', actor: 'GPT-4o', text: 'We should check who was quiet last night.' },
];
</script>

<template>
    <div
        class="overflow-hidden border border-white/10 bg-dark-primary/85 shadow-2xl shadow-black/50"
        aria-hidden="true"
    >
        <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-mist">Live game</p>
                <p class="text-sm font-semibold text-gray-100">Night, Round 2</p>
            </div>
            <span class="status-pill bg-white/5 text-slate-300 ring-white/10">Night</span>
        </div>

        <div class="grid sm:grid-cols-[11rem_1fr]">
            <aside class="border-b border-white/10 p-3 sm:border-b-0 sm:border-r">
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-mist">Players</p>
                <ul class="space-y-2">
                    <li
                        v-for="player in players"
                        :key="player.name"
                        class="border border-white/10 px-2.5 py-2"
                        :class="player.alive ? 'bg-dark-secondary/70' : 'opacity-45'"
                    >
                        <p class="text-xs font-semibold text-gray-100">{{ player.name }}</p>
                        <p class="text-[11px] text-mist">{{ player.detail }}</p>
                    </li>
                </ul>
            </aside>

            <div class="min-h-[16rem] p-3">
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-mist">Game log</p>
                <ul class="space-y-2">
                    <li
                        v-for="(entry, i) in log"
                        :key="i"
                        class="border-l-2 px-3 py-2 text-sm"
                        :class="{
                            'border-white/20 bg-white/[0.03] text-mist': entry.kind === 'phase',
                            'border-white/15 bg-dark-secondary/50 text-slate-300': entry.kind === 'speech',
                            'border-white/10 bg-dark-primary/70 text-mist italic': entry.kind === 'thought',
                        }"
                    >
                        <template v-if="entry.kind === 'phase'">{{ entry.text }}</template>
                        <template v-else-if="entry.kind === 'thought'">
                            <span class="not-italic text-slate-400">{{ entry.actor }} thinking: </span>{{ entry.text }}
                        </template>
                        <template v-else>
                            <span class="font-semibold text-gray-100">{{ entry.actor }}</span>:
                            {{ entry.text }}
                        </template>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
