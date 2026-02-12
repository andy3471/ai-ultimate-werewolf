<script setup lang="ts">
import GameLayout from '@/layouts/GameLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { store } from '@/actions/App/Http/Controllers/GameController';

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
    if (!existingNames.includes(baseName)) return baseName;
    let i = 2;
    while (existingNames.includes(`${baseName} #${i}`)) i++;
    return `${baseName} #${i}`;
}

function buildDefaultPlayers(): PlayerSlot[] {
    const defaults: PlayerSlot[] = [];
    const providers = props.availableProviders;
    const personalities = props.availablePersonalities;

    // Spread models across available providers for variety
    const allModels: { provider: string; model: string; modelName: string }[] = [];
    for (const provider of providers) {
        for (const model of provider.models) {
            allModels.push({ provider: provider.id, model: model.id, modelName: model.name });
        }
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

const submitting = ref(false);

function getModelsForProvider(providerId: string): ModelOption[] {
    return props.availableProviders.find((p) => p.id === providerId)?.models ?? [];
}

function getModelName(providerId: string, modelId: string): string {
    const provider = props.availableProviders.find((p) => p.id === providerId);
    return provider?.models.find((m) => m.id === modelId)?.name ?? modelId;
}

function addPlayer() {
    const providerIndex = players.value.length % props.availableProviders.length;
    const provider = props.availableProviders[providerIndex];
    const personalityIndex = players.value.length % props.availablePersonalities.length;
    const modelName = provider.models[0].name;
    const usedNames = players.value.map((p) => p.name);

    players.value.push({
        name: uniqueName(modelName, usedNames),
        provider: provider.id,
        model: provider.models[0].id,
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
        onFinish: () => { submitting.value = false; },
    });
}

const canSubmit = computed(() => players.value.length >= 5 && players.value.length <= 12);

const providerColors: Record<string, string> = {
    openai: 'border-green-600/40 bg-green-950/20',
    anthropic: 'border-orange-600/40 bg-orange-950/20',
    gemini: 'border-blue-600/40 bg-blue-950/20',
};

const providerAccent: Record<string, string> = {
    openai: 'text-green-400',
    anthropic: 'text-orange-400',
    gemini: 'text-blue-400',
};
</script>

<template>
    <GameLayout>
        <Head title="Create Game" />

        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-neutral-100">Create New Game</h1>
                <p class="mt-1 text-neutral-400">
                    Configure {{ players.length }} AI players to battle in a game of Werewolf.
                    Minimum 5 players, maximum 12.
                </p>
            </div>

            <div class="space-y-4">
                <div
                    v-for="(player, index) in players"
                    :key="index"
                    :class="[
                        'rounded-xl border p-4 transition-all',
                        providerColors[player.provider] || 'border-neutral-800 bg-neutral-900/50',
                    ]"
                >
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-neutral-800 text-lg font-bold text-neutral-300">
                            {{ index + 1 }}
                        </div>

                        <div class="flex-1 space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-400">Display Name</label>
                                <input
                                    v-model="player.name"
                                    type="text"
                                    placeholder="Player name"
                                    maxlength="30"
                                    class="w-full rounded-lg border border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-neutral-400">Provider</label>
                                    <select
                                        v-model="player.provider"
                                        @change="onProviderChange(index)"
                                        class="w-full rounded-lg border border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                    >
                                        <option v-for="provider in availableProviders" :key="provider.id" :value="provider.id">
                                            {{ provider.name }}
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-neutral-400">Model</label>
                                    <select
                                        v-model="player.model"
                                        @change="onModelChange(index)"
                                        class="w-full rounded-lg border border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                    >
                                        <option v-for="model in getModelsForProvider(player.provider)" :key="model.id" :value="model.id">
                                            {{ model.name }}
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-neutral-400">Personality</label>
                                    <select
                                        v-model="player.personality"
                                        class="w-full rounded-lg border border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-neutral-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                    >
                                        <option v-for="personality in availablePersonalities" :key="personality" :value="personality">
                                            {{ personality.length > 50 ? personality.slice(0, 50) + '...' : personality }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <button
                            v-if="players.length > 5"
                            @click="removePlayer(index)"
                            class="mt-5 shrink-0 rounded-lg p-1.5 text-neutral-500 transition hover:bg-neutral-800 hover:text-red-400"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path
                                    fill-rule="evenodd"
                                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </button>
                    </div>

                    <div class="mt-2 pl-14">
                        <span :class="['text-xs font-medium', providerAccent[player.provider] || 'text-neutral-400']">
                            {{ getModelName(player.provider, player.model) }} ({{ player.provider }})
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-4">
                <button
                    v-if="players.length < 12"
                    @click="addPlayer"
                    class="inline-flex items-center gap-2 rounded-lg border border-dashed border-neutral-700 px-4 py-2.5 text-sm text-neutral-400 transition hover:border-neutral-600 hover:text-neutral-300"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path
                            fill-rule="evenodd"
                            d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    Add Player
                </button>
            </div>

            <div class="mt-8 flex items-center justify-between border-t border-neutral-800 pt-6">
                <div class="text-sm text-neutral-400">
                    {{ players.length }} players ({{ players.length <= 6 ? 1 : players.length <= 11 ? 2 : 3 }} Werewolf{{ (players.length <= 6 ? 1 : players.length <= 11 ? 2 : 3) > 1 ? 'es' : '' }}, 1 Seer, 1 Bodyguard, {{ Math.max(0, players.length - (players.length <= 6 ? 1 : players.length <= 11 ? 2 : 3) - 2) }} Villager{{ Math.max(0, players.length - (players.length <= 6 ? 1 : players.length <= 11 ? 2 : 3) - 2) !== 1 ? 's' : '' }})
                </div>
                <button
                    @click="submit"
                    :disabled="!canSubmit || submitting"
                    :class="[
                        'inline-flex items-center gap-2 rounded-lg px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition',
                        canSubmit && !submitting
                            ? 'bg-indigo-600 hover:bg-indigo-500'
                            : 'cursor-not-allowed bg-neutral-700 text-neutral-400',
                    ]"
                >
                    <span v-if="submitting">Creating...</span>
                    <span v-else>Create Game</span>
                </button>
            </div>
        </div>
    </GameLayout>
</template>
