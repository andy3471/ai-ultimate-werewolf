<script setup lang="ts">
withDefaults(
    defineProps<{
        size?: 'sm' | 'md' | 'lg' | 'hero';
        showWordmark?: boolean;
    }>(),
    {
        size: 'md',
        showWordmark: true,
    },
);

const moonClass = {
    sm: 'size-5',
    md: 'size-6',
    lg: 'size-10',
    hero: 'size-14',
} as const;

const wordClass = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-2xl',
    hero: 'text-5xl sm:text-6xl lg:text-7xl',
} as const;
</script>

<template>
    <span
        class="inline-flex items-center gap-2.5"
        :class="size === 'hero' ? 'flex-col items-start gap-4 sm:flex-row sm:items-end' : ''"
    >
        <span
            class="night-moon relative inline-flex shrink-0 items-center justify-center rounded-full"
            :class="moonClass[size]"
            aria-hidden="true"
        >
            <span
                class="absolute inset-[18%] rounded-full bg-dark-primary/90"
                style="transform: translate(22%, -10%)"
            />
        </span>
        <span
            v-if="showWordmark"
            class="gradient-logo marker-wiggle leading-none select-none"
            :class="wordClass[size]"
        >
            AI Werewolf
        </span>
        <span v-else class="sr-only">AI Werewolf</span>
    </span>
</template>
