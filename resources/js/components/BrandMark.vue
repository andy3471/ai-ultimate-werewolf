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

const wordClass = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-2xl',
    hero: 'text-5xl sm:text-6xl lg:text-7xl',
} as const;
</script>

<template>
    <span
        v-if="showWordmark"
        class="brand-logo marker-wiggle leading-none select-none"
        :class="wordClass[size]"
    >
        AI Werewolf
    </span>
    <span v-else class="sr-only">AI Werewolf</span>
</template>
