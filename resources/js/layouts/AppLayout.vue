<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, provide, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import AuthDrawer from '../components/auth/AuthDrawer.vue';
import MatrixRainBackground from '../components/MatrixRainBackground.vue';
import NavIcon from '../components/NavIcon.vue';
import SmokeBackground from '../components/SmokeBackground.vue';
import { useAuth } from '../composables/useAuth';
import { useTheme } from '../composables/useTheme';
import { getLocale, setLocale } from '../i18n';
import { routes } from '../lib/routes';

const currentLocale = ref(getLocale());
const { t } = useI18n();
const { theme, initTheme, toggleTheme } = useTheme();

interface NavLink {
    label: string;
    href?: string;
    active?: boolean;
    icon?: string;
    children?: {
        label: string;
        href: string;
        active?: boolean;
        icon?: string;
    }[];
}

defineProps<{
    navLinks?: NavLink[];
}>();

const page = usePage();
const { user: currentUser, isAdmin } = useAuth();
const brandTitle = computed(() => page.props.name || 'CHY Lab');

const defaultNavLinks = computed((): NavLink[] => {
    const path = page.url;
    const aviationActive =
        path.startsWith(routes.airports()) ||
        path.startsWith(routes.airlines()) ||
        path.startsWith(routes.countries());

    return [
        {
            label: t('articles.nav.home'),
            href: routes.home(),
            icon: 'home',
            active:
                path.replace(/\/$/, '') === routes.home().replace(/\/$/, ''),
        },
        {
            label: t('articles.nav.articles'),
            href: routes.articles.index(),
            icon: 'articles',
            active: path.startsWith(routes.articles.index()),
        },
        {
            label: t('articles.nav.aviation'),
            icon: 'aviation',
            active: aviationActive,
            children: [
                {
                    label: t('articles.nav.airports'),
                    href: routes.airports(),
                    icon: 'airports',
                    active: path.startsWith(routes.airports()),
                },
                {
                    label: t('articles.nav.airlines'),
                    href: routes.airlines(),
                    icon: 'airlines',
                    active: path.startsWith(routes.airlines()),
                },
                {
                    label: t('articles.nav.countries'),
                    href: routes.countries(),
                    icon: 'countries',
                    active: path.startsWith(routes.countries()),
                },
            ],
        },
        {
            label: t('articles.nav.about'),
            href: routes.about(),
            icon: 'about',
            active: path.startsWith(routes.about()),
        },
        {
            label: 'LineBot',
            href: routes.linebot(),
            icon: 'linebot',
            active: path.startsWith(routes.linebot()),
        },
        {
            label: 'Lab',
            icon: 'lab',
            active:
                path.startsWith(routes.tourPlayground()) ||
                path.startsWith(routes.miniOrch()) ||
                path.startsWith(routes.wsLab()) ||
                path.startsWith(routes.gacha()) ||
                path.startsWith(routes.computerVision()) ||
                path.startsWith(routes.mcp()) ||
                path.startsWith(routes.memory()) ||
                (isAdmin.value && path.startsWith(routes.storyRelay())),
            children: [
                {
                    label: 'Tour',
                    href: routes.tourPlayground(),
                    icon: 'tour',
                    active: path.startsWith(routes.tourPlayground()),
                },
                {
                    label: 'mini-orch',
                    href: routes.miniOrch(),
                    icon: 'orch',
                    active: path.startsWith(routes.miniOrch()),
                },
                {
                    label: 'ws-lab',
                    href: routes.wsLab(),
                    icon: 'wslab',
                    active: path.startsWith(routes.wsLab()),
                },
                {
                    label: 'Gacha',
                    href: routes.gacha(),
                    icon: 'gacha',
                    active: path.startsWith(routes.gacha()),
                },
                {
                    label: 'CV',
                    href: routes.computerVision(),
                    icon: 'cv',
                    active: path.startsWith(routes.computerVision()),
                },
                {
                    label: 'MCP',
                    href: routes.mcp(),
                    icon: 'mcp',
                    active: path.startsWith(routes.mcp()),
                },
                {
                    label: 'Memory',
                    href: routes.memory(),
                    icon: 'memory',
                    active: path.startsWith(routes.memory()),
                },
                ...(isAdmin.value
                    ? [
                          {
                              label: 'Story',
                              href: routes.storyRelay(),
                              icon: 'story',
                              active: path.startsWith(routes.storyRelay()),
                          },
                      ]
                    : []),
            ],
        },
    ];
});

const effectiveUser = computed(() => currentUser.value);

const authDrawerOpen = ref(false);
const authDrawerTab = ref<'login' | 'register' | 'profile'>('login');

function toggleDrawer(tab: 'login' | 'register' | 'profile') {
    if (authDrawerOpen.value && authDrawerTab.value === tab) {
        authDrawerOpen.value = false;
    } else {
        authDrawerTab.value = tab;
        authDrawerOpen.value = true;
    }
}

onMounted(() => {
    initTheme();
});

// 全域通知狀態
const toast = ref<{ message: string; type?: 'success' | 'error' } | null>(null);
let toastTimer: number | null = null;
function showToast(message: string, type: 'success' | 'error' = 'success') {
    toast.value = { message, type };

    if (toastTimer) {
        clearTimeout(toastTimer);
    }

    toastTimer = window.setTimeout(() => {
        toast.value = null;
    }, 3200);
}

provide('showToast', showToast);

function toggleLocale() {
    const next = currentLocale.value === 'zh-tw' ? 'en' : 'zh-tw';
    setLocale(next as 'zh-tw' | 'en');
    currentLocale.value = next;
}
</script>

<template>
    <div
        class="binary-page selection:bg-[var(--binary-primary-container)] selection:text-[var(--binary-on-primary-container)]"
    >
        <!-- Background -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <MatrixRainBackground v-if="theme === 'emerald'" />
            <SmokeBackground v-else />
            <div class="bg-anim-glow" />
        </div>

        <!-- Overlay: transparent blur above animation, below content -->
        <!-- <div class="pointer-events-none fixed inset-0" style="z-index: -5; background: rgba(11, 16, 13, 0); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);" /> -->

        <!-- Navbar -->
        <nav class="binary-glass fixed top-0 right-0 left-0 z-50">
            <div
                class="mx-auto flex w-full max-w-screen-2xl items-center justify-between px-6 py-4 md:px-8"
            >
                <!-- Logo -->
                <Link
                    :href="routes.home()"
                    class="binary-display flex items-center gap-3 text-xl font-black tracking-tight text-[var(--binary-primary)] uppercase"
                >
                    <div class="nav-glyph">
                        <div class="layer"><div class="square outer" /></div>
                        <div class="layer"><div class="square" /></div>
                        <div class="layer"><div class="square inner" /></div>
                    </div>
                    {{ brandTitle }}
                </Link>

                <!-- Nav Links -->
                <div
                    class="binary-label hidden items-center gap-8 text-xs text-[var(--binary-outline)] uppercase md:flex"
                >
                    <slot name="nav-links">
                        <template
                            v-for="link in navLinks ?? defaultNavLinks"
                            :key="link.label"
                        >
                            <!-- Dropdown -->
                            <details v-if="link.children" class="relative">
                                <summary
                                    class="binary-link flex cursor-pointer list-none items-center gap-1.5 hover:text-[var(--binary-primary)]"
                                    :class="
                                        link.active
                                            ? 'text-[var(--binary-primary)]'
                                            : ''
                                    "
                                >
                                    <NavIcon
                                        v-if="link.icon"
                                        :name="link.icon"
                                    />
                                    {{ link.label }}
                                    <svg
                                        class="h-3 w-3 opacity-60"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </summary>
                                <div
                                    class="absolute top-full left-0 mt-2 min-w-[140px] rounded-xl bg-[var(--binary-surface-high)] p-1.5 shadow-[0_16px_40px_rgba(0,0,0,0.35)]"
                                >
                                    <a
                                        v-for="child in link.children"
                                        :key="child.href"
                                        :href="child.href"
                                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-[var(--binary-text)] transition hover:bg-[var(--binary-surface-container)] hover:text-[var(--binary-primary)]"
                                        :class="
                                            child.active
                                                ? 'text-[var(--binary-primary)]'
                                                : ''
                                        "
                                    >
                                        <NavIcon
                                            v-if="child.icon"
                                            :name="child.icon"
                                        />
                                        {{ child.label }}
                                    </a>
                                </div>
                            </details>
                            <!-- Flat link -->
                            <a
                                v-else
                                :href="link.href"
                                class="binary-link flex items-center gap-1.5 hover:text-[var(--binary-primary)]"
                                :class="
                                    link.active
                                        ? 'text-[var(--binary-primary)]'
                                        : ''
                                "
                            >
                                <NavIcon v-if="link.icon" :name="link.icon" />
                                {{ link.label }}
                            </a>
                        </template>
                    </slot>
                </div>

                <!-- Auth Area -->
                <div class="flex items-center gap-3">
                    <!-- Theme toggle -->
                    <button
                        class="binary-label rounded px-2 py-1 text-[10px] font-bold uppercase transition"
                        :style="{
                            color: theme === 'emerald' ? '#ffb690' : '#6bdc9f',
                        }"
                        :title="
                            theme === 'emerald'
                                ? 'Switch to Amber & Cosmic'
                                : 'Switch to Emerald Terminal'
                        "
                        @click="toggleTheme"
                    >
                        ◈
                    </button>
                    <!-- Locale toggle -->
                    <button
                        class="binary-label rounded px-2 py-1 text-[10px] font-bold text-[var(--binary-outline)] uppercase transition hover:text-[var(--binary-primary)]"
                        @click="toggleLocale"
                    >
                        {{ currentLocale === 'zh-tw' ? '中' : 'EN' }}
                    </button>
                    <!-- Logged in: avatar button -->
                    <template v-if="effectiveUser">
                        <!-- Admin link -->
                        <a
                            v-if="isAdmin"
                            :href="routes.admin.system()"
                            class="binary-label rounded px-2 py-1 text-[10px] font-bold text-[var(--binary-primary)] uppercase transition hover:opacity-70"
                        >
                            {{ t('layout.admin_system') }}
                        </a>
                        <button
                            type="button"
                            class="rounded-full ring-2 ring-transparent transition hover:ring-[var(--binary-primary)]/50 focus:outline-none"
                            :class="
                                authDrawerOpen && authDrawerTab === 'profile'
                                    ? 'ring-[var(--binary-primary)]/70'
                                    : ''
                            "
                            @click="toggleDrawer('profile')"
                        >
                            <img
                                :src="
                                    String(
                                        effectiveUser.avatar ||
                                            routes.assets.avatarDefault('user'),
                                    )
                                "
                                alt="avatar"
                                class="h-8 w-8 rounded-full object-cover"
                            />
                        </button>
                    </template>
                    <!-- Guest: user icon button -->
                    <template v-else>
                        <button
                            type="button"
                            class="flex items-center rounded-md p-2 text-[var(--binary-outline)] transition hover:text-[var(--binary-primary)]"
                            :class="
                                authDrawerOpen
                                    ? 'text-[var(--binary-primary)]'
                                    : ''
                            "
                            @click="toggleDrawer('login')"
                        >
                            <svg
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                                />
                            </svg>
                        </button>
                    </template>
                </div>
            </div>
        </nav>

        <!-- Toast 通知 -->
        <transition name="fade">
            <div
                v-if="toast"
                class="fixed top-8 left-1/2 z-[9999] -translate-x-1/2 rounded-2xl px-8 py-4 shadow-xl"
                :class="
                    toast.type === 'success'
                        ? 'bg-[var(--binary-primary)]/90 text-[var(--binary-on-primary-container)]'
                        : 'bg-[var(--binary-tertiary)]/90 text-[var(--binary-on-primary-container)]'
                "
                style="
                    font-size: 1.1rem;
                    letter-spacing: -0.5px;
                    min-width: 240px;
                    text-align: center;
                    backdrop-filter: blur(12px);
                "
            >
                {{ toast.message }}
            </div>
        </transition>

        <!-- Page content -->
        <div class="nav-pt">
            <slot />
        </div>

        <!-- Auth drawer -->
        <AuthDrawer v-model:open="authDrawerOpen" v-model:tab="authDrawerTab" />
    </div>
</template>
