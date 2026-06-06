<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, provide, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import AuthDrawer from '../components/auth/AuthDrawer.vue';
import MatrixRainBackground from '../components/MatrixRainBackground.vue';
import NavDrawer from '../components/NavDrawer.vue';
import NavIcon from '../components/NavIcon.vue';
import SmokeBackground from '../components/SmokeBackground.vue';
import { useAuth } from '../composables/useAuth';
import { useTheme } from '../composables/useTheme';
import { getLocale, setLocale } from '../i18n';
import { routes } from '../lib/routes';

const currentLocale = ref(getLocale());
const { t } = useI18n();
// eslint-disable-next-line @typescript-eslint/no-unused-vars
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

// 行動版左側導覽抽屜
const navMenuOpen = ref(false);

// 左右兩個抽屜互斥：開一個就收另一個
function toggleNavMenu() {
    navMenuOpen.value = !navMenuOpen.value;

    if (navMenuOpen.value) {
        authDrawerOpen.value = false;
    }
}

function toggleDrawer(tab: 'login' | 'register' | 'profile') {
    if (authDrawerOpen.value && authDrawerTab.value === tab) {
        authDrawerOpen.value = false;
    } else {
        authDrawerTab.value = tab;
        authDrawerOpen.value = true;
        navMenuOpen.value = false;
    }
}

// Navbar 下拉：受控開關，點外面 / Esc / 選項目皆自動收起
const openMenu = ref<string | null>(null);
function toggleMenu(label: string) {
    openMenu.value = openMenu.value === label ? null : label;
}
function closeMenu() {
    openMenu.value = null;
}
const onDocClick = (e: MouseEvent) => {
    if (!(e.target as HTMLElement).closest('[data-nav-dropdown]')) {
        openMenu.value = null;
    }
};
const onDocKey = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        openMenu.value = null;
    }
};

onMounted(() => {
    initTheme();

    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onDocKey);

    // OAuth 註冊被擋時 callback 會帶 ?auth_error 跳回首頁，這裡轉成 toast 並清掉 query。
    const params = new URLSearchParams(window.location.search);

    if (params.get('auth_error') === 'registration_closed') {
        showToast(t('auth.registration_closed'), 'error');
        params.delete('auth_error');
        const qs = params.toString();
        window.history.replaceState(
            {},
            '',
            window.location.pathname +
                (qs ? `?${qs}` : '') +
                window.location.hash,
        );
    }
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

onUnmounted(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onDocKey);
});

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
                class="relative mx-auto flex w-full max-w-screen-2xl items-center justify-between px-6 py-4 md:px-8"
            >
                <!-- Left: 漢堡（行動版）+ Logo（桌機） -->
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="flex items-center rounded-md p-2 text-[var(--binary-text)] transition hover:text-[var(--binary-primary)] md:hidden"
                        :aria-label="
                            navMenuOpen
                                ? 'Close navigation menu'
                                : 'Open navigation menu'
                        "
                        @click="toggleNavMenu"
                    >
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                v-if="navMenuOpen"
                                stroke-linecap="round"
                                d="M6 6l12 12M18 6L6 18"
                            />
                            <path
                                v-else
                                stroke-linecap="round"
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                        </svg>
                    </button>
                    <Link
                        :href="routes.home()"
                        class="binary-display hidden items-center gap-3 text-xl font-black tracking-tight text-[var(--binary-primary)] uppercase md:flex"
                    >
                        <div class="nav-glyph">
                            <div class="layer">
                                <div class="square outer" />
                            </div>
                            <div class="layer"><div class="square" /></div>
                            <div class="layer">
                                <div class="square inner" />
                            </div>
                        </div>
                        {{ brandTitle }}
                    </Link>
                </div>

                <!-- Center: 站名（行動版限定，絕對置中） -->
                <Link
                    :href="routes.home()"
                    class="binary-display absolute left-1/2 -translate-x-1/2 text-base font-black tracking-tight text-[var(--binary-primary)] uppercase md:hidden"
                >
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
                            <div
                                v-if="link.children"
                                class="relative"
                                data-nav-dropdown
                            >
                                <button
                                    type="button"
                                    class="binary-link flex cursor-pointer items-center gap-1.5 hover:text-[var(--binary-primary)]"
                                    :class="
                                        link.active
                                            ? 'text-[var(--binary-primary)]'
                                            : ''
                                    "
                                    @click="toggleMenu(link.label)"
                                >
                                    <NavIcon
                                        v-if="link.icon"
                                        :name="link.icon"
                                    />
                                    {{ link.label }}
                                    <svg
                                        class="h-3 w-3 opacity-60 transition-transform"
                                        :class="
                                            openMenu === link.label
                                                ? 'rotate-180'
                                                : ''
                                        "
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                                <div
                                    v-if="openMenu === link.label"
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
                                        @click="closeMenu"
                                    >
                                        <NavIcon
                                            v-if="child.icon"
                                            :name="child.icon"
                                        />
                                        {{ child.label }}
                                    </a>
                                </div>
                            </div>
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
                    <!-- Theme toggle (disabled) -->
                    <button
                        class="binary-label cursor-not-allowed rounded px-2 py-1 text-[10px] font-bold uppercase opacity-30"
                        disabled
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
        <div
            class="pointer-events-none fixed top-20 right-0 left-0 z-[9999] flex justify-center"
        >
            <transition name="toast-slide">
                <div
                    v-if="toast"
                    class="pointer-events-auto inline-flex items-center gap-2.5 rounded-none border-[0.5px] border-l-[3px] border-[var(--binary-outline-variant)] border-l-[var(--binary-toast-accent)] bg-[var(--binary-surface-dim)] px-[18px] py-2.5 text-[13px] text-[var(--binary-text)] shadow-lg backdrop-blur-md"
                    style="font-family: var(--font-binary, monospace)"
                >
                    <span class="toast-mark shrink-0" aria-hidden="true" />
                    <span>{{ toast.message }}</span>
                </div>
            </transition>
        </div>

        <!-- Page content -->
        <div class="nav-pt">
            <slot />
        </div>

        <!-- 行動版導覽抽屜（左側滑入） -->
        <NavDrawer
            v-model:open="navMenuOpen"
            :links="navLinks ?? defaultNavLinks"
        />

        <!-- Auth drawer -->
        <AuthDrawer v-model:open="authDrawerOpen" v-model:tab="authDrawerTab" />
    </div>
</template>

<style scoped>
/* Toast 前綴符號：由 --binary-toast-mark 帶，依主題切換（emerald '>' / amber '◆'） */
.toast-mark::before {
    content: var(--binary-toast-mark, '>');
    color: var(--binary-toast-accent);
    font-weight: 700;
}

/* Toast：從 navbar 下方往下滑入 + 淡入，離場往上收 */
.toast-slide-enter-from,
.toast-slide-leave-to {
    opacity: 0;
    transform: translateY(-16px);
}

.toast-slide-enter-active {
    transition:
        opacity 0.28s ease,
        transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
}

.toast-slide-leave-active {
    transition:
        opacity 0.18s ease,
        transform 0.18s ease-in;
}
</style>
