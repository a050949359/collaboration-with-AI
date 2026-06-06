<script setup lang="ts">
import BlobBackground from '../components/BlobBackground.vue';
import MatrixRainBackground from '../components/MatrixRainBackground.vue';
import { useTheme } from '../composables/useTheme';

const { theme } = useTheme();
</script>

<template>
    <div class="binary-page">
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <MatrixRainBackground v-if="theme === 'emerald'" />
            <BlobBackground v-else />
        </div>
        <div class="flex min-h-screen items-center justify-center px-6 py-20">
            <div class="w-full max-w-md">
                <slot />
            </div>
        </div>
    </div>
</template>
