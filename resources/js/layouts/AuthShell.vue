<script setup lang="ts">
interface Props {
    eyebrow: string;
    title: string;
    summary: string;
    mode: 'login' | 'register';
}

defineProps<Props>();
</script>

<template>
    <div class="binary-page">
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="absolute inset-0 binary-grid opacity-[0.04]" />
            <div class="absolute right-0 top-0 h-[42vw] w-[42vw] bg-[#6bdc9f]/[0.08] blur-[140px]" />
            <div class="absolute bottom-0 left-0 h-[34vw] w-[34vw] bg-[#2ca46d]/20 blur-[110px]" />
        </div>

        <main class="binary-shell">
            <section class="relative hidden w-1/2 flex-col justify-between bg-[var(--binary-surface)] px-16 py-18 lg:flex">
                <div>
                    <p class="binary-label mb-8 text-xs font-bold uppercase text-[var(--binary-primary)]">
                        > ACCOUNT_CENTER
                    </p>
                    <h1 class="binary-display text-6xl font-bold uppercase leading-[0.92] tracking-tight text-[var(--binary-text)] xl:text-7xl">
                        <span class="block">Secure</span>
                        <span class="ml-12 block text-[var(--binary-primary)]">Access</span>
                    </h1>
                    <p class="mt-8 max-w-[26rem] text-base leading-8 text-[var(--binary-text-muted)]">
                        這裡提供統一的登入與註冊入口，讓使用者可以用清楚、穩定的流程完成身分驗證與帳號建立。
                    </p>
                </div>

                <div>
                    <div class="flex items-center gap-4">
                        <div class="h-px w-14 bg-[var(--binary-primary)] transition-all duration-300 hover:w-24" />
                        <span class="binary-label text-[10px] uppercase text-[var(--binary-outline)]">
                            account access / stable
                        </span>
                    </div>
                </div>
            </section>

            <section class="flex w-full items-center justify-center px-6 py-10 md:px-12 lg:w-1/2 lg:px-20">
                <div class="w-full max-w-md">
                    <div class="mb-12">
                        <p class="binary-label mb-4 text-xs font-bold uppercase text-[var(--binary-primary)]">
                            {{ eyebrow }}
                        </p>
                        <h2 class="binary-display text-4xl font-bold tracking-tight text-[var(--binary-text)]">
                            {{ title }}
                        </h2>
                        <p class="mt-4 text-sm leading-7 text-[var(--binary-text-muted)]">
                            {{ summary }}
                        </p>
                    </div>

                    <div class="binary-panel rounded-[1.75rem] p-8 md:p-10">
                        <slot />
                    </div>

                    <div class="mt-10 opacity-45">
                        <p class="binary-label text-[10px] uppercase leading-6 text-[var(--binary-outline)]">
                            connection_status: secure_tls_1.3
                            <br>
                            session_context: authenticated_entry_flow
                            <br>
                            {{ mode === 'login' ? 'sign in to continue' : 'create your account to get started' }}
                        </p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>