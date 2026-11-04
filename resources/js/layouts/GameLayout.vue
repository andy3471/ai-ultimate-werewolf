<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { create, index } from '@/actions/App/Http/Controllers/GameController';
import BrandMark from '@/components/BrandMark.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { home, login, rules } from '@/routes';

const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentUrl } = useCurrentUrl();

const navLinkClass = (href: string) =>
    [
        'rounded-md px-3 py-2 text-sm font-medium transition-colors',
        isCurrentUrl(href)
            ? 'text-moon'
            : 'text-mist hover:text-moon',
    ].join(' ');
</script>

<template>
    <div class="app-atmosphere relative flex min-h-screen flex-col text-gray-100">
        <div
            class="lofi-grain pointer-events-none absolute inset-0 z-0 opacity-[0.035] mix-blend-overlay"
            aria-hidden="true"
        />

        <a href="#main" class="skip-link">Skip to content</a>

        <header class="relative z-20 border-b border-white/10 bg-dark-primary/80 backdrop-blur-md">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <Link :href="auth.user ? index.url() : home()" class="shrink-0">
                    <BrandMark size="md" />
                </Link>

                <nav class="flex items-center gap-1" aria-label="Primary">
                    <Link
                        v-if="auth.user"
                        :href="index.url()"
                        :class="navLinkClass(index.url())"
                    >
                        Games
                    </Link>
                    <Link :href="rules.url()" :class="navLinkClass(rules.url())">
                        Rules
                    </Link>
                    <Link
                        v-if="auth.user"
                        :href="create.url()"
                        class="btn-brand-solid ml-2 hidden px-3 py-1.5 sm:inline-flex"
                    >
                        New game
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="btn-brand ml-2 hidden px-3 py-1.5 sm:inline-flex"
                    >
                        Log in
                    </Link>
                </nav>
            </div>
        </header>

        <main id="main" class="relative z-10" tabindex="-1">
            <slot />
        </main>

        <SiteFooter />
    </div>
</template>
