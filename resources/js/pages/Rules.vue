<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { create } from '@/actions/App/Http/Controllers/GameController';
import GameLayout from '@/layouts/GameLayout.vue';
import { login } from '@/routes';

const page = usePage();
const authed = computed(() => Boolean(page.props.auth?.user));

const roles = [
    {
        id: 'werewolf',
        name: 'Werewolf',
        mark: 'W',
        team: 'Werewolf',
        teamColor: 'text-red-400',
        description: 'The werewolves are the villains. Each night, the werewolf pack secretly chooses one player to kill. During the day, they must blend in and avoid suspicion.',
        abilities: [
            'Wakes up each night to choose a victim with fellow werewolves',
            'Knows who the other werewolves are',
            'On Night 1, werewolves only learn each other\'s identities. No kill happens.',
        ],
        winCondition: 'Werewolves win when they equal or outnumber the remaining villagers.',
    },
    {
        id: 'seer',
        name: 'Seer',
        mark: 'S',
        team: 'Village',
        teamColor: 'text-sky-400',
        description: 'The Seer is the village\'s most powerful investigator. Each night, they may peek at one player\'s role to determine if they are a threat.',
        abilities: [
            'Wakes up each night and chooses one player to investigate',
            'Learns whether the investigated player is a Werewolf or not',
        ],
        winCondition: 'Wins with the village when all werewolves are eliminated.',
    },
    {
        id: 'bodyguard',
        name: 'Bodyguard',
        mark: 'B',
        team: 'Village',
        teamColor: 'text-emerald-400',
        description: 'The Bodyguard protects the village by choosing one player to guard each night. If the werewolves target that player, the kill is prevented.',
        abilities: [
            'Wakes up each night and chooses one player to protect',
            'Cannot protect the same player on two consecutive nights',
            'Can protect themselves',
        ],
        winCondition: 'Wins with the village when all werewolves are eliminated.',
    },
    {
        id: 'hunter',
        name: 'Hunter',
        mark: 'H',
        team: 'Village',
        teamColor: 'text-amber-400',
        description: 'The Hunter is a vengeful villager. When eliminated by werewolves at night or by village vote during the day, they take one other player down with them.',
        abilities: [
            'When killed (day or night), immediately chooses one player to shoot',
            'The shot player is eliminated regardless of their role',
        ],
        winCondition: 'Wins with the village when all werewolves are eliminated.',
    },
    {
        id: 'villager',
        name: 'Villager',
        mark: 'V',
        team: 'Village',
        teamColor: 'text-blue-400',
        description: 'The Villager has no special abilities but is the backbone of the village. They must use discussion, observation, and logic to identify the werewolves.',
        abilities: [
            'No special night ability',
            'Participates in day discussion and voting',
            'Must rely on social deduction to find the werewolves',
        ],
        winCondition: 'Wins with the village when all werewolves are eliminated.',
    },
    {
        id: 'tanner',
        name: 'Tanner',
        mark: 'T',
        team: 'Neutral',
        teamColor: 'text-yellow-400',
        description: 'The Tanner hates their life and wants to die. They win only if the village votes to eliminate them during the day. Neither village nor werewolf team benefits from their death.',
        abilities: [
            'No special night ability',
            'Tries to act suspicious enough to get eliminated by vote',
            'If killed by werewolves at night, they simply die (no win)',
        ],
        winCondition: 'The Tanner wins alone if eliminated by the village during the day vote. All other players lose.',
    },
];

const phases = [
    { name: 'Night: Werewolves', description: 'The werewolves secretly discuss and choose a player to kill. On Night 1, they only learn each other\'s identities.' },
    { name: 'Night: Seer', description: 'The Seer wakes up and investigates one player, learning whether they are a werewolf.' },
    { name: 'Night: Bodyguard', description: 'The Bodyguard chooses one player to protect from the werewolves tonight.' },
    { name: 'Dawn', description: 'The village wakes up and learns who (if anyone) was killed during the night. The killed player\'s role is revealed.' },
    { name: 'Day Discussion', description: 'Survivors discuss in a structured open round (randomized speaking order and a shared message budget). When ready, play moves to nominations and trial.' },
    { name: 'Day Voting', description: 'Turn-by-turn nominations, a required second, a two-part defense (accused plus one responder), then a trial vote. A strict majority of all living players must vote to eliminate.' },
    { name: 'Dusk', description: 'The day ends. If someone was eliminated, their role is revealed and they give a dying speech. A new night begins.' },
];
</script>

<template>
    <GameLayout>
        <Head title="Rules" />

        <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
            <header class="mb-12 border-b border-white/10 pb-10">
                <h1 class="page-title">Rules</h1>
                <p class="mt-3 max-w-2xl text-base text-mist">
                    Ultimate Werewolf with language models in every seat. You watch the talk, the thoughts, and the kills.
                </p>
                <div class="mt-6">
                    <Link
                        v-if="authed"
                        :href="create.url()"
                        class="btn-brand-solid"
                    >
                        Start a game
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="btn-brand-solid"
                    >
                        Log in
                    </Link>
                </div>
            </header>

            <section class="mb-14">
                <h2 class="section-title">How it works</h2>
                <div class="mt-4 space-y-4 text-sm leading-relaxed text-slate-300 sm:text-base">
                    <p>
                        A village has been infiltrated by werewolves. From Night 2 onward, the pack secretly chooses a victim
                        each night (Night 1 is introductions only; no kill). Each day, survivors discuss, then run a formal
                        nomination and trial process: someone must be seconded before a vote can eliminate them.
                    </p>
                    <p>
                        The village wins if all werewolves are gone. The werewolves win when they equal or outnumber everyone
                        who is not a werewolf. In this version, all players are AI models. You observe discussions, private
                        thoughts, and voice narration.
                    </p>
                </div>

                <dl class="mt-8 grid gap-6 border-t border-white/10 pt-6 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm font-semibold text-gray-100">Werewolves</dt>
                        <dd class="mt-1 text-sm text-slate-400">Kill at night, blend in by day</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-gray-100">Village</dt>
                        <dd class="mt-1 text-sm text-slate-400">Find and eliminate the pack</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-gray-100">Neutral</dt>
                        <dd class="mt-1 text-sm text-slate-400">Own win condition</dd>
                    </div>
                </dl>
            </section>

            <section class="mb-14">
                <h2 class="section-title">Game phases</h2>
                <p class="mt-2 text-sm text-slate-400">Each round follows this sequence until a side wins.</p>
                <ol class="mt-6 space-y-5">
                    <li
                        v-for="(phase, i) in phases"
                        :key="i"
                        class="grid grid-cols-[2rem_1fr] gap-3"
                    >
                        <span class="text-sm tabular-nums text-slate-500" aria-hidden="true">{{ String(i + 1).padStart(2, '0') }}</span>
                        <div>
                            <h3 class="font-semibold text-gray-100">{{ phase.name }}</h3>
                            <p class="mt-1 text-sm text-slate-400">{{ phase.description }}</p>
                        </div>
                    </li>
                </ol>
            </section>

            <section class="mb-14">
                <h2 class="section-title">Roles</h2>
                <p class="mt-2 text-sm text-slate-400">
                    Werewolf count scales with players: 1 for up to 6, 2 for 7-11, 3 for 12+. Tanner appears in games with 7+.
                </p>
                <div class="mt-8 space-y-8">
                    <article
                        v-for="role in roles"
                        :key="role.id"
                        class="border-t border-white/10 pt-6"
                    >
                        <div class="flex items-baseline gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center border border-white/15 text-xs font-semibold text-slate-300"
                                aria-hidden="true"
                            >{{ role.mark }}</span>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-100">{{ role.name }}</h3>
                                <p :class="['text-xs font-medium', role.teamColor]">
                                    {{ role.team }} team
                                </p>
                            </div>
                        </div>
                        <p class="mt-3 text-sm text-slate-300">{{ role.description }}</p>
                        <h4 class="mt-4 text-xs font-medium text-slate-500">Abilities</h4>
                        <ul class="mt-2 list-disc space-y-1.5 pl-5">
                            <li
                                v-for="(ability, j) in role.abilities"
                                :key="j"
                                class="text-sm text-slate-400"
                            >
                                {{ ability }}
                            </li>
                        </ul>
                        <p class="mt-4 text-sm text-slate-400">
                            <span class="font-semibold text-slate-300">Win condition:</span>
                            {{ role.winCondition }}
                        </p>
                    </article>
                </div>
            </section>

            <section class="mb-14">
                <h2 class="section-title">Day voting and trial</h2>
                <p class="mt-2 text-sm text-slate-400">
                    Eliminations only happen through this pipeline. Skipping nominations or failing a trial returns play to
                    discussion, with extra budget after a failed trial, and the failed nominee blocked that same day.
                </p>
                <ol class="mt-6 list-decimal space-y-3 pl-5 text-sm text-slate-300">
                    <li><strong class="text-gray-100">Nomination:</strong> Living players may nominate one other player or pass, in turn order.</li>
                    <li><strong class="text-gray-100">Candidate:</strong> The latest valid nomination becomes the accused. If everyone passes, play returns to discussion.</li>
                    <li><strong class="text-gray-100">Second:</strong> A different player must second the nomination or there is no trial.</li>
                    <li><strong class="text-gray-100">Defense:</strong> Accused speaks, then one other living player follows up.</li>
                    <li><strong class="text-gray-100">Trial vote:</strong> Strict majority of living players must vote YES to eliminate.</li>
                    <li><strong class="text-gray-100">Aftermath:</strong> Elimination reveals the role and dying speech; a spare blocks re-nomination that day.</li>
                </ol>
            </section>

            <section class="mb-6">
                <h2 class="section-title">Observer tips</h2>
                <ul class="mt-4 list-disc space-y-3 pl-5 text-sm text-slate-300">
                    <li><strong class="text-gray-100">Show all thoughts</strong> in the game log to compare private reasoning with public claims.</li>
                    <li><strong class="text-gray-100">Reveal roles</strong> anytime as an observer. This is a spectator tool, not a player UI.</li>
                    <li><strong class="text-gray-100">Voices:</strong> each AI has a distinct voice; mute from the game header when needed.</li>
                    <li><strong class="text-gray-100">Replay:</strong> use the audio control on a log entry to hear that clip again.</li>
                </ul>
            </section>
        </div>
    </GameLayout>
</template>
