<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BrandMark from '@/components/BrandMark.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="app-atmosphere relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 text-gray-100 md:p-10">
        <div class="relative z-10 w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link :href="home()" class="flex flex-col items-center gap-3">
                        <BrandMark size="lg" />
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium text-gray-100">{{ title }}</h1>
                        <p class="text-center text-sm text-mist">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <div class="text-gray-100 [&_label]:text-slate-300 [&_a]:text-moon [&_a]:underline [&_a]:underline-offset-4 [&_.text-muted-foreground]:text-mist">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
