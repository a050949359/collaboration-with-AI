<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getAuthApiConfig, logoutWithApi } from '../lib/auth-api';
import { setLocale, getLocale } from '../i18n';

const { t } = useI18n();
const currentLocale = ref(getLocale());

const props = defineProps<{
    navLinks?: { label: string; href: string; active?: boolean }[];
}>();

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);
const apiUser = ref<null | Record<string, unknown>>(null);
const effectiveUser = computed(() => currentUser.value ?? apiUser.value);
const isAdmin = computed(
    () => !!(page.props.auth?.is_admin || apiUser.value?.role === 'admin'),
);
const isLoggingOut = ref(false);

function toggleLocale() {
    const next = currentLocale.value === 'zh-tw' ? 'en' : 'zh-tw';
    setLocale(next as 'zh-tw' | 'en');
    currentLocale.value = next;
}

function bindGoogle() {
    window.location.href = '/api/auth/google/redirect';
}

onMounted(async () => {
    if (currentUser.value || typeof window === 'undefined') return;

    const config = getAuthApiConfig();
    const token = window.localStorage.getItem(config.tokenStorageKey);
    if (!token) return;

    try {
        const res = await fetch(`${config.baseUrl.replace(/\/$/, '')}/api/auth/me`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        });
        if (res.ok) apiUser.value = await res.json();
    } catch {
        // keep guest UI
    }
});

async function logout() {
    if (isLoggingOut.value) return;
    isLoggingOut.value = true;
    try {
        await logoutWithApi();
        router.visit('/', { replace: true });
    } finally {
        isLoggingOut.value = false;
    }
}
</script>

<template>
    <div class="binary-page selection:bg-[var(--binary-primary-container)] selection:text-[var(--binary-on-primary-container)]">
        <!-- Background -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="absolute inset-0 binary-grid opacity-[0.03]" />
            <div class="absolute right-0 top-0 h-[40vw] w-[40vw] bg-[#6bdc9f]/[0.06] blur-[140px]" />
            <div class="absolute bottom-0 left-0 h-[30vw] w-[30vw] bg-[#2ca46d]/16 blur-[120px]" />
        </div>

        <!-- Navbar -->
        <nav class="binary-glass fixed left-0 right-0 top-0 z-50">
            <div class="mx-auto flex w-full max-w-screen-2xl items-center justify-between px-6 py-4 md:px-8">
                <!-- Logo -->
                <Link href="/" class="binary-display text-xl font-black uppercase tracking-tight text-[var(--binary-primary)]">
                    BINARY_EDITORIAL
                </Link>

                <!-- Nav Links -->
                <div class="hidden items-center gap-8 binary-label text-xs uppercase text-[var(--binary-outline)] md:flex">
                    <slot name="nav-links">
                        <a
                            v-for="link in (navLinks ?? [])"
                            :key="link.href"
                            :href="link.href"
                            class="binary-link hover:text-[var(--binary-primary)]"
                            :class="link.active ? 'text-[var(--binary-primary)]' : ''"
                        >
                            {{ link.label }}
                        </a>
                    </slot>
                </div>

                <!-- Auth Area -->
                <div class="flex items-center gap-3">
                    <!-- Locale toggle -->
                    <button
                        class="binary-label rounded px-2 py-1 text-[10px] font-bold uppercase text-[var(--binary-outline)] transition hover:text-[var(--binary-primary)]"
                        @click="toggleLocale"
                    >
                        {{ currentLocale === 'zh-tw' ? '中' : 'EN' }}
                    </button>
                    <template v-if="effectiveUser">
                        <details class="relative">
                            <summary class="flex list-none cursor-pointer items-center gap-2 rounded-md bg-[var(--binary-surface-container)] px-3 py-2 binary-label text-xs font-bold uppercase text-[var(--binary-primary)]">
                                <img
                                    :src="String(effectiveUser.avatar || '/avatar/default/user.svg')"
                                    alt="avatar"
                                    class="h-7 w-7 rounded-full object-cover"
                                >
                                <span class="hidden sm:inline">{{ effectiveUser.name }}</span>
                            </summary>

                            <div class="absolute right-0 mt-2 w-56 rounded-xl bg-[var(--binary-surface-high)] p-2 shadow-[0_16px_40px_rgba(0,0,0,0.35)]">
                                <div class="mb-2 flex items-center gap-2 rounded-lg bg-[var(--binary-surface-container)] p-2">
                                    <img
                                        :src="String(effectiveUser.avatar || '/avatar/default/user.svg')"
                                        alt="avatar"
                                        class="h-8 w-8 rounded-full object-cover"
                                    >
                                    <div class="min-w-0">
                                        <p class="truncate binary-label text-[11px] font-bold uppercase text-[var(--binary-text)]">{{ effectiveUser.name }}</p>
                                        <p class="truncate text-[10px] text-[var(--binary-outline)]">{{ effectiveUser.email }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="w-full rounded-lg px-3 py-2 text-left binary-label text-xs uppercase text-[var(--binary-text)] transition hover:bg-[var(--binary-surface-container)]"
                                    @click="bindGoogle"
                                >
                                    綁定 Google
                                </button>
                                <a
                                    v-if="isAdmin"
                                    href="/admin/settings"
                                    class="mt-1 block w-full rounded-lg px-3 py-2 text-left binary-label text-xs uppercase text-[var(--binary-primary)] transition hover:bg-[var(--binary-surface-container)]"
                                >
                                    系統設定
                                </a>
                                <button
                                    type="button"
                                    :disabled="isLoggingOut"
                                    class="mt-1 w-full rounded-lg px-3 py-2 text-left binary-label text-xs uppercase text-[var(--binary-text)] transition hover:bg-[var(--binary-surface-container)] disabled:opacity-50"
                                    @click="logout"
                                >
                                    {{ isLoggingOut ? 'Logging out...' : 'Logout' }}
                                </button>
                            </div>
                        </details>
                    </template>
                    <template v-else>
                        <Link class="binary-ghost-button hidden sm:inline-flex" href="/login">Login</Link>
                        <Link
                            class="rounded-md px-6 py-2 binary-display text-xs font-bold uppercase text-[var(--binary-on-primary-container)]"
                            href="/register"
                            style="background: linear-gradient(145deg, var(--binary-primary) 0%, var(--binary-primary-container) 100%);"
                        >
                            Register
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <!-- Page content -->
        <slot />
    </div>
</template>
