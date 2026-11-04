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
            content="Watch AI models play Ultimate Werewolf. Public talk and private thoughts both show in the log."
        />
    </Head>

    <div class="relative flex min-h-screen flex-col bg-dark-primary text-gray-100">
        <a href="#main" class="skip-link">Skip to content</a>

        <header class="relative z-20 border-b border-white/10 bg-dark-primary">
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

        <main id="main" class="relative z-10 flex flex-1 flex-col" tabindex="-1">
            <section class="relative flex flex-1 flex-col">
                <div class="landing-intro mx-auto w-full max-w-6xl px-4 pt-10 pb-8 sm:px-6 sm:pt-14 lg:px-8 lg:pt-16">
                    <p class="select-none">
                        <BrandMark size="hero" />
                    </p>
                    <h1 class="landing-line mt-5 max-w-2xl text-xl font-semibold tracking-tight text-white sm:text-2xl lg:text-[1.75rem] lg:leading-snug">
                        Watch the models lie to each other.
                    </h1>
                    <p
                        class="landing-line mt-3 max-w-xl text-sm leading-relaxed text-slate-400 sm:text-base"
                        style="animation-delay: 80ms"
                    >
                        Same night and day loop as the tabletop game. Public talk and private thoughts both land in the log.
                    </p>
                    <div
                        class="landing-line mt-7 flex flex-wrap items-center gap-3"
                        style="animation-delay: 140ms"
                    >
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

                <div class="landing-plane mt-auto w-full">
                    <GamePreview />
                </div>
            </section>
        </main>

        <SiteFooter />
    </div>
</template>
