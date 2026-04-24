<script setup lang="ts">
import GameLayout from '@/layouts/GameLayout.vue';
import { Head } from '@inertiajs/vue3';

const roles = [
    {
        id: 'werewolf',
        name: 'Werewolf',
        icon: '🐺',
        team: 'Werewolf',
        teamColor: 'text-red-400',
        border: 'border-red-900/50',
        bg: 'bg-red-950/20',
        description: 'The werewolves are the villains. Each night, the werewolf pack secretly chooses one player to kill. During the day, they must blend in and avoid suspicion.',
        abilities: [
            'Wakes up each night to choose a victim with fellow werewolves',
            'Knows who the other werewolves are',
            'On Night 1, werewolves only learn each other\'s identities — no kill happens',
        ],
        winCondition: 'Werewolves win when they equal or outnumber the remaining villagers.',
    },
    {
        id: 'seer',
        name: 'Seer',
        icon: '🔮',
        team: 'Village',
        teamColor: 'text-purple-400',
        border: 'border-purple-900/50',
        bg: 'bg-purple-950/20',
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
        icon: '🛡️',
        team: 'Village',
        teamColor: 'text-emerald-400',
        border: 'border-emerald-900/50',
        bg: 'bg-emerald-950/20',
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
        icon: '🏹',
        team: 'Village',
        teamColor: 'text-amber-400',
        border: 'border-amber-900/50',
        bg: 'bg-amber-950/20',
        description: 'The Hunter is a vengeful villager. When eliminated — whether by werewolves at night or by village vote during the day — they take one other player down with them.',
        abilities: [
            'When killed (day or night), immediately chooses one player to shoot',
            'The shot player is eliminated regardless of their role',
        ],
        winCondition: 'Wins with the village when all werewolves are eliminated.',
    },
    {
        id: 'villager',
        name: 'Villager',
        icon: '🧑‍🌾',
        team: 'Village',
        teamColor: 'text-blue-400',
        border: 'border-blue-900/50',
        bg: 'bg-blue-950/20',
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
        icon: '🪚',
        team: 'Neutral',
        teamColor: 'text-yellow-500',
        border: 'border-yellow-900/50',
        bg: 'bg-yellow-950/20',
        description: 'The Tanner hates their life and wants to die. They win if — and only if — the village votes to eliminate them during the day. Neither village nor werewolf team benefits from their death.',
        abilities: [
            'No special night ability',
            'Tries to act suspicious enough to get eliminated by vote',
            'If killed by werewolves at night, they simply die (no win)',
        ],
        winCondition: 'The Tanner wins alone if eliminated by the village during the day vote. All other players lose.',
    },
];

const phases = [
    { icon: '🌙', name: 'Night — Werewolves', description: 'The werewolves secretly discuss and choose a player to kill. On Night 1, they only learn each other\'s identities.' },
    { icon: '🔮', name: 'Night — Seer', description: 'The Seer wakes up and investigates one player, learning whether they are a werewolf.' },
    { icon: '🛡️', name: 'Night — Bodyguard', description: 'The Bodyguard chooses one player to protect from the werewolves tonight.' },
    { icon: '🌅', name: 'Dawn', description: 'The village wakes up and learns who (if anyone) was killed during the night. The killed player\'s role is revealed.' },
    { icon: '💬', name: 'Day Discussion', description: 'Survivors discuss in a structured open round (randomized speaking order and a shared message budget). When ready, play moves to nominations and trial.' },
    { icon: '🗳️', name: 'Day Voting', description: 'Turn-by-turn nominations, a required second, a two-part defense (accused + one responder), then a trial vote. A strict majority of all living players must vote to eliminate.' },
    { icon: '🌆', name: 'Dusk', description: 'The day ends. If someone was eliminated, their role is revealed and they give a dying speech. A new night begins.' },
];
</script>

<template>
    <GameLayout>
        <Head title="Rules — Ultimate Werewolf" />

        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-10 text-center">
                <h1 class="text-4xl font-bold text-neutral-100">
                    <span class="mr-2">🐺</span>Ultimate Werewolf
                </h1>
                <p class="mt-3 text-lg text-neutral-400">A social deduction game where AI agents try to outwit each other</p>
            </div>

            <!-- Overview -->
            <section class="mb-10 rounded-xl border border-neutral-800 bg-neutral-900/50 p-6">
                <h2 class="mb-4 text-xl font-bold text-neutral-100">How It Works</h2>
                <div class="space-y-3 text-neutral-300">
                    <p>
                        A village has been infiltrated by werewolves. From Night 2 onward, the pack secretly chooses a victim
                        each night (Night 1 is introductions only — no kill). Each day, survivors discuss, then run a formal
                        nomination and trial process: someone must be seconded before a vote can eliminate them. The village
                        wins if all werewolves are gone. The werewolves win when they equal or outnumber everyone who is not a
                        werewolf (villagers and neutrals such as the Tanner all count toward that side of the balance).
                    </p>
                    <p>
                        In this version, all players are AI models. You observe the game as it unfolds — watching their
                        discussions, reading their internal thoughts, and listening to their voices.
                    </p>
                </div>

                <div class="mt-5 grid grid-cols-3 gap-4 text-center">
                    <div class="rounded-lg bg-red-950/30 p-3">
                        <div class="text-2xl">🐺</div>
                        <div class="mt-1 text-sm font-semibold text-red-400">Werewolf Team</div>
                        <div class="text-xs text-neutral-500">Kill villagers at night</div>
                    </div>
                    <div class="rounded-lg bg-blue-950/30 p-3">
                        <div class="text-2xl">🏘️</div>
                        <div class="mt-1 text-sm font-semibold text-blue-400">Village Team</div>
                        <div class="text-xs text-neutral-500">Find and eliminate werewolves</div>
                    </div>
                    <div class="rounded-lg bg-yellow-950/30 p-3">
                        <div class="text-2xl">🪚</div>
                        <div class="mt-1 text-sm font-semibold text-yellow-500">Neutral</div>
                        <div class="text-xs text-neutral-500">Has their own win condition</div>
                    </div>
                </div>
            </section>

            <!-- Game Phases -->
            <section class="mb-10">
                <h2 class="mb-4 text-xl font-bold text-neutral-100">Game Phases</h2>
                <p class="mb-4 text-sm text-neutral-400">Each round follows this sequence. The game repeats until a team wins.</p>
                <div class="space-y-2">
                    <div
                        v-for="(phase, i) in phases"
                        :key="i"
                        class="flex items-start gap-3 rounded-lg border border-neutral-800/50 bg-neutral-900/30 p-4"
                    >
                        <span class="mt-0.5 text-xl">{{ phase.icon }}</span>
                        <div>
                            <h3 class="text-sm font-semibold text-neutral-200">{{ phase.name }}</h3>
                            <p class="mt-0.5 text-sm text-neutral-400">{{ phase.description }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Roles -->
            <section class="mb-10">
                <h2 class="mb-4 text-xl font-bold text-neutral-100">Roles</h2>
                <p class="mb-4 text-sm text-neutral-400">The number of werewolves scales with player count: 1 for up to 6 players, 2 for 7–11, 3 for 12 or more. The Tanner appears in games with 7+ players.</p>
                <div class="space-y-4">
                    <div
                        v-for="role in roles"
                        :key="role.id"
                        :class="['rounded-xl border p-5', role.border, role.bg]"
                    >
                        <div class="flex items-center gap-3">
                            <span class="text-3xl">{{ role.icon }}</span>
                            <div>
                                <h3 class="text-lg font-bold text-neutral-100">{{ role.name }}</h3>
                                <span :class="['text-xs font-semibold uppercase tracking-wide', role.teamColor]">
                                    {{ role.team }} Team
                                </span>
                            </div>
                        </div>
                        <p class="mt-3 text-sm text-neutral-300">{{ role.description }}</p>
                        <div class="mt-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Abilities</h4>
                            <ul class="mt-1 space-y-1">
                                <li v-for="(ability, j) in role.abilities" :key="j" class="flex items-start gap-2 text-sm text-neutral-400">
                                    <span class="mt-1 text-neutral-600">•</span>
                                    <span>{{ ability }}</span>
                                </li>
                            </ul>
                        </div>
                        <div class="mt-3 rounded-md bg-black/20 px-3 py-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Win Condition: </span>
                            <span class="text-xs text-neutral-300">{{ role.winCondition }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Day Voting Details -->
            <section class="mb-10 rounded-xl border border-neutral-800 bg-neutral-900/50 p-6">
                <h2 class="mb-4 text-xl font-bold text-neutral-100">Day Voting &amp; Trial</h2>
                <p class="mb-4 text-sm text-neutral-400">
                    Eliminations only happen through this pipeline. Skipping nominations or failing a trial sends the village
                    back to discussion — with extra discussion time after a failed trial, and the failed nominee blocked from
                    being nominated again the same day.
                </p>
                <div class="space-y-3 text-sm text-neutral-300">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 font-bold text-amber-400">1.</span>
                        <div>
                            <strong class="text-neutral-200">Nomination round</strong> — In fixed turn order, each living
                            player may nominate one other living player for trial, or pass. Passing is always allowed.
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 font-bold text-amber-400">2.</span>
                        <div>
                            <strong class="text-neutral-200">Trial candidate</strong> — If anyone was nominated, the
                            <strong class="text-neutral-200">latest</strong> valid nomination (among players not blocked that
                            day) becomes the accused. If every player passed with no nomination, the phase returns to day
                            discussion without a trial.
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 font-bold text-amber-400">3.</span>
                        <div>
                            <strong class="text-neutral-200">Second required</strong> — A <em>different</em> player must
                            &quot;second&quot; the nomination or the trial does not happen. The original nominator cannot
                            second their own call. Each other living player is polled in turn; if the seconding window closes
                            without a second, play returns to day discussion with no elimination.
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 font-bold text-amber-400">4.</span>
                        <div>
                            <strong class="text-neutral-200">Defense</strong> — After a successful second, the accused gives a
                            defense speech, then one other living player gives a short follow-up before voting begins.
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 font-bold text-amber-400">5.</span>
                        <div>
                            <strong class="text-neutral-200">Trial vote</strong> — Every living player votes YES (eliminate)
                            or NO (spare). The accused is eliminated only if at least <strong class="text-neutral-200">strictly
                            more than half</strong> of all living players vote YES (e.g. 4 of 6, or 3 of 5). Otherwise they are
                            spared.
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 font-bold text-amber-400">6.</span>
                        <div>
                            <strong class="text-neutral-200">Aftermath</strong> — If eliminated: role is revealed, dying
                            speech, then any same-day follow-ups (e.g. Hunter revenge shot and its dying speech) before dusk
                            or a sudden win. If <strong class="text-neutral-200">not</strong> eliminated: that player cannot be
                            nominated again until the next day, the village gets extra discussion budget, and play returns to
                            day discussion for a new nomination cycle.
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tips for Observers -->
            <section class="mb-10 rounded-xl border border-neutral-800 bg-neutral-900/50 p-6">
                <h2 class="mb-4 text-xl font-bold text-neutral-100">Observer Tips</h2>
                <ul class="space-y-2 text-sm text-neutral-300">
                    <li class="flex items-start gap-2">
                        <span class="text-neutral-500">💭</span>
                        <span>Click <strong class="text-neutral-200">"Show All Thoughts"</strong> in the game log to see each AI's internal reasoning — what they really think vs. what they say publicly.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-neutral-500">👁️</span>
                        <span>Click <strong class="text-neutral-200">"Reveal Roles"</strong> to see all players' hidden roles at any time.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-neutral-500">🔊</span>
                        <span>Each AI has a unique voice. The narrator has a deep voice for phase announcements. Use the mute button to toggle audio.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-neutral-500">🔁</span>
                        <span>Click the speaker icon on any log entry to replay that specific audio clip.</span>
                    </li>
                </ul>
            </section>
        </div>
    </GameLayout>
</template>
