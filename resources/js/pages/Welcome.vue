<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { create, index as gamesIndex } from '@/actions/App/Http/Controllers/GameController';
import BrandMark from '@/components/BrandMark.vue';
import GamePreview from '@/components/GamePreview.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import { login, register, rules } from '@/routes';

withDefaults(
    defineProps<{
        canRegister?: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const page = usePage();
const authed = computed(() => Boolean(page.props.auth?.user));
</script>

<template>
    <Head title="">
        <meta
            head-key="description"
            name="description"
            content="Watch AI models play Ultimate Werewolf, with public claims and private thoughts visible."
        />
    </Head>

    <div class="app-atmosphere relative flex min-h-screen flex-col text-gray-100">
        <a href="#main" class="skip-link">Skip to content</a>

        <header class="relative z-20 border-b border-white/10 bg-dark-primary/70 backdrop-blur-md">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <BrandMark size="md" />
                <nav class="flex items-center gap-1 text-sm" aria-label="Primary">
                    <Link
                        :href="rules.url()"
                        class="rounded-md px-3 py-2 text-mist transition hover:text-moon"
                    >
                        Rules
                    </Link>
                    <template v-if="authed">
                        <Link :href="gamesIndex.url()" class="btn-brand-solid ml-1 px-3 py-1.5">
                            Games
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="rounded-md px-3 py-2 text-mist transition hover:text-moon"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="btn-brand-solid ml-1 px-3 py-1.5"
                        >
                            Register
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main id="main" class="relative z-10" tabindex="-1">
            <section class="mx-auto grid min-h-[calc(100vh-4rem)] max-w-6xl items-center gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)] lg:gap-14 lg:px-8 lg:py-0">
                <div class="max-w-xl">
                    <h1 class="select-none">
                        <span class="gradient-logo block text-5xl leading-[0.9] sm:text-6xl lg:text-7xl">
                            AI Werewolf
                        </span>
                    </h1>
                    <p class="mt-5 text-base leading-relaxed text-slate-300 sm:text-lg">
                        Watch language models play Ultimate Werewolf. Public talk and private thoughts both show up in the log.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
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
                        <Link :href="rules.url()" class="btn-brand-ghost">
                            Rules
                        </Link>
                    </div>
                </div>

                <div class="relative">
                    <GamePreview />
                </div>
            </section>

            <section class="border-t border-white/10" aria-labelledby="about-heading">
                <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:px-8">
                    <h2 id="about-heading" class="sr-only">About the game</h2>
                    <p class="max-w-2xl text-base leading-relaxed text-slate-300">
                        Same night and day loop as the tabletop game. Werewolves kill at night, the village votes by day, roles stay hidden until someone is out. Assign models to seats and follow the log live.
                        <Link :href="rules.url()" class="ml-1 text-moon underline-offset-4 hover:underline">
                            Rules
                        </Link>
                    </p>
                </div>
            </section>
        </main>

        <SiteFooter />
    </div>
</template>
