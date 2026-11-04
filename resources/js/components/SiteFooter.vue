<script lang="ts" setup>
const year = new Date().getFullYear();
</script>

<template>
    <footer class="relative z-10 mt-auto border-t border-white/10 bg-dark-primary">
        <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-6 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <p class="text-sm text-mist">
                Built by
                <span class="brand-logo marker-wiggle ml-1 inline-block text-base leading-none">
                    Andrew Hargrave
                </span>
            </p>
            <div class="flex items-center gap-4 text-sm">
                <a
                    href="https://andyh.app"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-mist transition-colors hover:text-moon"
                >Portfolio</a>
                <a
                    href="https://github.com/andy3471/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-mist transition-colors hover:text-moon"
                >GitHub</a>
                <span class="text-xs text-white/40">© {{ year }}</span>
            </div>
        </div>
    </footer>
</template>
