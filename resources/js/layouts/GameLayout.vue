<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { index } from '@/actions/App/Http/Controllers/GameController';
</script>

<template>
    <div class="min-h-screen bg-neutral-950 text-neutral-100">
        <nav class="border-b border-neutral-800/50 bg-neutral-950/80 backdrop-blur-sm">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <Link :href="index.url()" class="flex items-center gap-2 text-lg font-bold text-neutral-100 transition hover:text-white">
                    <span class="text-2xl">🐺</span>
                    <span>AI Werewolf</span>
                </Link>
            </div>
        </nav>

        <main>
            <slot />
        </main>
    </div>
</template>
