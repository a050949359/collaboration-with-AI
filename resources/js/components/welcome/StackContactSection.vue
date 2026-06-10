<script setup lang="ts">
defineProps<{
    stackInfo: [string, string][];
}>();

const githubUrl = 'https://github.com/a050949359/collaboration-with-AI';
</script>

<template>
    <section
        class="binary-section flex min-h-[calc(100vh-4rem)] flex-col justify-center bg-[var(--binary-surface-lowest)]"
    >
        <div class="mx-auto max-w-screen-2xl">
            <div
                class="mb-8 flex flex-col gap-4 md:mb-12 md:flex-row md:items-baseline md:justify-between"
            >
                <h2
                    class="binary-display text-2xl font-black tracking-tight uppercase md:text-6xl"
                >
                    Technology Stack
                </h2>
                <div
                    class="binary-label text-sm tracking-[0.2em] text-[var(--binary-outline)] uppercase"
                >
                    &gt; stack.info
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
                <div
                    v-for="[label, value] in stackInfo"
                    :key="label"
                    class="binary-accent-card rounded-none p-4 md:rounded-2xl md:p-5"
                >
                    <span
                        class="binary-label mb-3 block text-[10px] tracking-[0.2em] text-[var(--binary-outline)] uppercase"
                        >{{ label }}</span
                    >
                    <p
                        class="binary-label text-xs leading-relaxed text-[var(--binary-primary)]"
                    >
                        {{ value }}
                    </p>
                </div>
            </div>

            <div
                class="mt-10 flex flex-col items-center gap-6 text-center md:mt-20"
            >
                <p
                    class="binary-label text-xs tracking-[0.3em] text-[var(--binary-outline)] uppercase"
                >
                    &gt; view_source_repository
                </p>
                <a
                    :href="githubUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="binary-display inline-flex max-w-full flex-wrap items-center justify-center gap-2 rounded-xl border border-[var(--binary-primary)] px-4 py-3 text-xs font-bold break-all text-[var(--binary-primary)] uppercase transition-colors hover:bg-[var(--binary-primary)] hover:text-[var(--binary-on-primary)] md:gap-3 md:px-8 md:py-4 md:text-sm"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path
                            d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.385-1.335-1.755-1.335-1.755-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.605-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 21.795 24 17.295 24 12c0-6.63-5.37-12-12-12z"
                        />
                    </svg>
                    a050949359 / collaboration-with-AI
                </a>
            </div>
        </div>
    </section>
</template>
