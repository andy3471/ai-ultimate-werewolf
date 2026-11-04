<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { index, store } from '@/actions/App/Http/Controllers/GameController';
import RoleTooltip from '@/components/game/RoleTooltip.vue';
import GameLayout from '@/layouts/GameLayout.vue';

interface ModelOption {
    id: string;
    name: string;
}

interface ProviderOption {
    id: string;
    name: string;
    models: ModelOption[];
}

interface PlayerSlot {
    name: string;
    provider: string;
    model: string;
    personality: string;
}

const props = defineProps<{
    availableProviders: ProviderOption[];
    availablePersonalities: string[];
}>();

function uniqueName(baseName: string, existingNames: string[]): string {
    if (!existingNames.includes(baseName)) {
        return baseName;
    }
    let i = 2;
    while (existingNames.includes(`${baseName} #${i}`)) {
        i++;
    }
    return `${baseName} #${i}`;
}

function buildDefaultPlayers(): PlayerSlot[] {
    const defaults: PlayerSlot[] = [];
    const providers = props.availableProviders;
    const personalities = props.availablePersonalities;

    if (providers.length === 0 || personalities.length === 0) {
        return defaults;
    }

    const allModels: { provider: string; model: string; modelName: string }[] = [];
    for (const provider of providers) {
        for (const model of provider.models) {
            allModels.push({ provider: provider.id, model: model.id, modelName: model.name });
        }
    }

    if (allModels.length === 0) {
        return defaults;
    }

    const usedNames: string[] = [];
    const count = Math.min(7, Math.max(5, allModels.length));
    for (let i = 0; i < count; i++) {
        const entry = allModels[i % allModels.length];
        const name = uniqueName(entry.modelName, usedNames);
        usedNames.push(name);
        defaults.push({
            name,
            provider: entry.provider,
            model: entry.model,
            personality: personalities[i % personalities.length],
        });
    }

    return defaults;
}

const players = ref<PlayerSlot[]>(buildDefaultPlayers());
const hasProviders = computed(() => props.availableProviders.some((provider) => provider.models.length > 0));
const submitting = ref(false);

function getModelsForProvider(providerId: string): ModelOption[] {
    return props.availableProviders.find((p) => p.id === providerId)?.models ?? [];
}

function getModelName(providerId: string, modelId: string): string {
    const provider = props.availableProviders.find((p) => p.id === providerId);
    return provider?.models.find((m) => m.id === modelId)?.name ?? modelId;
}

function addPlayer() {
    if (!hasProviders.value) {
        return;
    }

    const providerIndex = players.value.length % props.availableProviders.length;
    const provider = props.availableProviders[providerIndex];
    const model = provider?.models[0];

    if (!provider || !model) {
        return;
    }

    const personalityIndex = players.value.length % props.availablePersonalities.length;
    const usedNames = players.value.map((p) => p.name);

    players.value.push({
        name: uniqueName(model.name, usedNames),
        provider: provider.id,
        model: model.id,
        personality: props.availablePersonalities[personalityIndex],
    });
}

function removePlayer(index: number) {
    if (players.value.length > 5) {
        players.value.splice(index, 1);
    }
}

function onProviderChange(index: number) {
    const slot = players.value[index];
    const models = getModelsForProvider(slot.provider);
    if (models.length > 0) {
        slot.model = models[0].id;
        const usedNames = players.value.filter((_, i) => i !== index).map((p) => p.name);
        slot.name = uniqueName(models[0].name, usedNames);
    }
}

function onModelChange(index: number) {
    const slot = players.value[index];
    const modelName = getModelName(slot.provider, slot.model);
    const usedNames = players.value.filter((_, i) => i !== index).map((p) => p.name);
    slot.name = uniqueName(modelName, usedNames);
}

function submit() {
    submitting.value = true;
    router.post(store.url(), { players: players.value }, {
        onFinish: () => {
            submitting.value = false;
        },
    });
}

const canSubmit = computed(
    () => hasProviders.value && players.value.length >= 5 && players.value.length <= 12,
);

const roleBreakdown = computed(() => {
    const n = players.value.length;
    const wolves = n <= 6 ? 1 : n <= 11 ? 2 : 3;
    const seer = 1;
    const bodyguard = 1;
    const hunter = 1;
    const tanner = n >= 7 ? 1 : 0;
    const specialCount = wolves + seer + bodyguard + hunter + tanner;
    const villagers = Math.max(0, n - specialCount);

    const roles: { name: string; icon: string; count: number; team: string; teamColor: string; bg: string }[] = [
        { name: 'Werewolf', icon: 'W', count: wolves, team: 'Werewolf', teamColor: 'text-red-400', bg: 'bg-red-950/40 ring-1 ring-red-900/40' },
        { name: 'Seer', icon: 'S', count: seer, team: 'Village', teamColor: 'text-sky-400', bg: 'bg-sky-950/30 ring-1 ring-sky-900/30' },
        { name: 'Bodyguard', icon: 'B', count: bodyguard, team: 'Village', teamColor: 'text-emerald-400', bg: 'bg-emerald-950/30 ring-1 ring-emerald-900/30' },
        { name: 'Hunter', icon: 'H', count: hunter, team: 'Village', teamColor: 'text-amber-400', bg: 'bg-amber-950/30 ring-1 ring-amber-900/30' },
    ];
    if (tanner) {
        roles.push({ name: 'Tanner', icon: 'T', count: tanner, team: 'Neutral', teamColor: 'text-accent-yellow', bg: 'bg-yellow-950/30 ring-1 ring-yellow-900/30' });
    }
    roles.push({ name: 'Villager', icon: 'V', count: villagers, team: 'Village', teamColor: 'text-accent-blue', bg: 'bg-blue-950/30 ring-1 ring-blue-900/30' });

    return roles;
});

const providerAccent: Record<string, string> = {
    openai: 'text-emerald-400',
    anthropic: 'text-orange-400',
    gemini: 'text-accent-blue',
};
</script>

<template>
    <GameLayout>
        <Head title="Create Game" />

        <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="mb-8">
                <Link :href="index.url()" class="link-quiet text-sm">
                    ← Back to games
                </Link>
                <h1 class="page-title mt-3">Create game</h1>
                <p class="mt-3 text-slate-400">
                    Configure {{ players.length }} AI players.
                    Minimum 5, maximum 12.
                </p>
            </div>

            <div
                v-if="!hasProviders"
                class="border border-accent-yellow/30 bg-accent-yellow/5 px-5 py-5 text-accent-yellow"
                role="alert"
            >
                <h2 class="font-semibold text-accent-yellow">No AI providers configured</h2>
                <p class="mt-2 text-sm text-accent-yellow/80">
                    Set at least one of
                    <code class="rounded bg-black/30 px-1.5 py-0.5">OPENAI_API_KEY</code>,
                    <code class="rounded bg-black/30 px-1.5 py-0.5">ANTHROPIC_API_KEY</code>,
                    or
                    <code class="rounded bg-black/30 px-1.5 py-0.5">GEMINI_API_KEY</code>
                    in the environment, then redeploy.
                </p>
            </div>

            <ol v-else class="space-y-3" aria-label="Player slots">
                <li
                    v-for="(player, index) in players"
                    :key="index"
                    class="surface-panel p-4"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-dark-tertiary text-sm font-semibold text-slate-200 ring-1 ring-white/10"
                            aria-hidden="true"
                        >
                            {{ index + 1 }}
                        </div>

                        <div class="min-w-0 flex-1 space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-400" :for="`name-${index}`">
                                    Display name
                                </label>
                                <input
                                    :id="`name-${index}`"
                                    v-model="player.name"
                                    type="text"
                                    placeholder="Player name"
                                    maxlength="30"
                                    class="field-brand"
                                >
                            </div>

                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-400" :for="`provider-${index}`">
                                        Provider
                                    </label>
                                    <select
                                        :id="`provider-${index}`"
                                        v-model="player.provider"
                                        class="field-brand"
                                        @change="onProviderChange(index)"
                                    >
                                        <option
                                            v-for="provider in availableProviders"
                                            :key="provider.id"
                                            :value="provider.id"
                                        >
                                            {{ provider.name }}
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-400" :for="`model-${index}`">
                                        Model
                                    </label>
                                    <select
                                        :id="`model-${index}`"
                                        v-model="player.model"
                                        class="field-brand"
                                        @change="onModelChange(index)"
                                    >
                                        <option
                                            v-for="model in getModelsForProvider(player.provider)"
                                            :key="model.id"
                                            :value="model.id"
                                        >
                                            {{ model.name }}
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-400" :for="`personality-${index}`">
                                        Personality
                                    </label>
                                    <select
                                        :id="`personality-${index}`"
                                        v-model="player.personality"
                                        class="field-brand"
                                    >
                                        <option
                                            v-for="personality in availablePersonalities"
                                            :key="personality"
                                            :value="personality"
                                        >
                                            {{ personality.length > 50 ? personality.slice(0, 50) + '...' : personality }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <p :class="['text-xs font-medium', providerAccent[player.provider] || 'text-slate-400']">
                                {{ getModelName(player.provider, player.model) }} ({{ player.provider }})
                            </p>
                        </div>

                        <button
                            v-if="players.length > 5"
                            type="button"
                            class="mt-6 shrink-0 rounded-md p-2 text-slate-500 transition hover:bg-white/5 hover:text-red-400"
                            :aria-label="`Remove player ${index + 1}`"
                            @click="removePlayer(index)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path
                                    fill-rule="evenodd"
                                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </button>
                    </div>
                </li>
            </ol>

            <div v-if="hasProviders" class="mt-4">
                <button
                    v-if="players.length < 12"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-dashed border-white/20 px-4 py-2.5 text-sm text-slate-400 transition hover:border-white/40 hover:text-slate-200"
                    @click="addPlayer"
                >
                    Add player
                </button>
            </div>

            <div v-if="hasProviders" class="mt-10 border-t border-white/10 pt-6">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span class="text-sm text-slate-400">{{ players.length }} players, role mix</span>
                    <RoleTooltip
                        v-for="role in roleBreakdown"
                        :key="role.name"
                        :role="role.name"
                    >
                        <span :class="['inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-medium text-gray-100', role.bg]">
                            <span class="text-[10px] font-semibold text-slate-400" aria-hidden="true">{{ role.icon }}</span>
                            <span>{{ role.count }}× {{ role.name }}</span>
                        </span>
                    </RoleTooltip>
                </div>
                <div class="flex justify-end">
                    <button
                        type="button"
                        class="btn-brand-solid disabled:cursor-not-allowed disabled:border-slate-600 disabled:bg-slate-700 disabled:text-slate-400"
                        :disabled="!canSubmit || submitting"
                        @click="submit"
                    >
                        <span v-if="submitting">Creating…</span>
                        <span v-else>Create game</span>
                    </button>
                </div>
            </div>
        </div>
    </GameLayout>
</template>
