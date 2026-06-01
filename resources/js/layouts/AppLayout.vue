<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import NavIcon from '../components/NavIcon.vue';
import { useAuth } from '../composables/useAuth';
import { getLocale, setLocale } from '../i18n';
import { logoutWithApi } from '../lib/auth-api';
import { routes, api } from '../lib/routes';

const currentLocale = ref(getLocale());
const { t } = useI18n();

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
const isLoggingOut = ref(false);

// ── Canvas Rain ──────────────────────────────────────────
const canvasRef = ref<HTMLCanvasElement | null>(null);
let rafId = 0;
let resizeHandler: () => void;

onMounted(() => {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d')!;

    const CHARS =
        'ｦｧｨｩｪｫｬｭｮｯｰｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉﾊﾋﾌﾍﾎﾏﾐﾑﾒﾓﾔﾕﾖﾗﾘﾙﾚﾛﾜﾝ0123456789ABCDEF'.split(
            '',
        );
    const COL_W = 14;
    const arcSize = (r: number) => 14 + 44 * r * r;

    interface Stream {
        col: number;
        size: number;
        speed: number;
        alpha: number;
        yOffset: number;
        textLen: number;
        y: number;
        history: string[];
    }

    function makeStream(col: number, cosVal: number, ratio: number): Stream {
        const textLen = 20 + Math.floor(Math.random() * 30);
        const ar = Math.abs(ratio);

        return {
            col,
            size: arcSize(ar),
            speed: 0.06 + (1 - cosVal) * 0.16,
            alpha: (0.06 + 0.94 * Math.pow(ar, 1.5)) * 0.45,
            yOffset: cosVal * -120,
            textLen,
            y: (Math.random() - 1) * 80,
            history: Array.from(
                { length: textLen },
                () => CHARS[Math.floor(Math.random() * CHARS.length)],
            ),
        };
    }

    let streams: Stream[] = [];

    function init() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        streams = [];
        const columns = Math.floor(canvas.width / COL_W);
        let i = 0;

        while (i < columns) {
            const ratio = (i / columns - 0.5) * 2;
            const cosVal = Math.cos(ratio * Math.PI * 0.42);
            const step = Math.max(
                1,
                Math.round(arcSize(Math.abs(ratio)) / COL_W),
            );

            if (Math.random() < 0.85 - 0.5 * ratio * ratio) {
                streams.push(makeStream(i, cosVal, ratio));
            }

            i += step;
        }
    }

    function loop() {
        ctx.fillStyle = 'rgba(0,0,0,0.18)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        for (const s of streams) {
            s.y += s.speed;

            if (Math.random() < 0.008) {
                s.history[Math.floor(Math.random() * s.textLen)] =
                    CHARS[Math.floor(Math.random() * CHARS.length)];
            }

            if (s.y * s.size + s.yOffset > canvas.height + 500) {
                s.y = -s.textLen;
            }

            ctx.font = `bold ${s.size}px monospace`;
            const x = s.col * COL_W;

            for (let i = 0; i < s.textLen; i++) {
                const y =
                    (s.y - i) * s.size * 0.78 + canvas.height / 2 + s.yOffset;

                if (y < 0 || y > canvas.height) {
                    continue;
                }

                const ta = s.alpha * (1 - i / s.textLen);

                if (ta <= 0) {
                    continue;
                }

                ctx.fillStyle =
                    i === 0
                        ? `rgba(200,255,220,${s.alpha})`
                        : i < 5
                          ? `rgba(107,220,159,${ta})`
                          : `rgba(40,120,80,${ta})`;
                ctx.fillText(s.history[i], x, y);
            }
        }

        rafId = requestAnimationFrame(loop);
    }

    init();
    loop();
    resizeHandler = init;
    window.addEventListener('resize', resizeHandler);
});

onUnmounted(() => {
    cancelAnimationFrame(rafId);
    window.removeEventListener('resize', resizeHandler);
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

function toggleLocale() {
    const next = currentLocale.value === 'zh-tw' ? 'en' : 'zh-tw';
    setLocale(next as 'zh-tw' | 'en');
    currentLocale.value = next;
}

function bindGoogle() {
    window.location.href = api.auth.googleRedirect();
}

async function resendVerification() {
    const _fetch =
        (typeof window !== 'undefined' && window.fetch) ||
        (typeof self !== 'undefined' && self.fetch) ||
        (typeof globalThis !== 'undefined' && globalThis.fetch);

    if (!_fetch) {
        showToast('瀏覽器不支援 fetch，請更新瀏覽器', 'error');

        return;
    }

    try {
        await _fetch(api.auth.resendVerification(), {
            method: 'POST',
            credentials: 'include',
        });
        showToast('驗證信已寄出，請至信箱收信', 'success');
    } catch {
        showToast('重寄失敗，請稍後再試', 'error');
    }
}

async function logout() {
    if (isLoggingOut.value) {
        return;
    }

    isLoggingOut.value = true;

    try {
        await logoutWithApi();
        router.visit(routes.home(), { replace: true });
    } finally {
        isLoggingOut.value = false;
    }
}
</script>

<template>
    <div
        class="binary-page selection:bg-[var(--binary-primary-container)] selection:text-[var(--binary-on-primary-container)]"
    >
        <!-- Background -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <canvas ref="canvasRef" class="bg-anim-rain" />
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
                    <!-- Locale toggle -->
                    <button
                        class="binary-label rounded px-2 py-1 text-[10px] font-bold text-[var(--binary-outline)] uppercase transition hover:text-[var(--binary-primary)]"
                        @click="toggleLocale"
                    >
                        {{ currentLocale === 'zh-tw' ? '中' : 'EN' }}
                    </button>
                    <template v-if="effectiveUser">
                        <details class="relative">
                            <summary
                                class="binary-label flex cursor-pointer list-none items-center gap-2 rounded-md bg-[var(--binary-surface-container)] px-3 py-2 text-xs font-bold text-[var(--binary-primary)] uppercase"
                            >
                                <img
                                    :src="
                                        String(
                                            effectiveUser.avatar ||
                                                routes.assets.avatarDefault(
                                                    'user',
                                                ),
                                        )
                                    "
                                    alt="avatar"
                                    class="h-7 w-7 rounded-full object-cover"
                                />
                                <span
                                    class="flex hidden items-center gap-1 sm:inline"
                                >
                                    {{ effectiveUser.name }}
                                    <svg
                                        v-if="effectiveUser.email_verified_at"
                                        class="inline-block h-4 w-4 text-[var(--binary-primary)]"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-label="已驗證信箱"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M16.707 6.293a1 1 0 010 1.414l-6.364 6.364a1 1 0 01-1.414 0l-3.182-3.182a1 1 0 111.414-1.414l2.475 2.475 5.657-5.657a1 1 0 011.414 0z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </span>
                            </summary>

                            <div
                                class="absolute right-0 mt-2 w-56 rounded-xl bg-[var(--binary-surface-high)] p-2 shadow-[0_16px_40px_rgba(0,0,0,0.35)]"
                            >
                                <div
                                    class="mb-2 flex items-center gap-2 rounded-lg bg-[var(--binary-surface-container)] p-2"
                                >
                                    <img
                                        :src="
                                            String(
                                                effectiveUser.avatar ||
                                                    routes.assets.avatarDefault(
                                                        'user',
                                                    ),
                                            )
                                        "
                                        alt="avatar"
                                        class="h-8 w-8 rounded-full object-cover"
                                    />
                                    <div class="min-w-0">
                                        <p
                                            class="binary-label truncate text-[11px] font-bold text-[var(--binary-text)] uppercase"
                                        >
                                            {{ effectiveUser.name }}
                                        </p>
                                        <p
                                            class="truncate text-[10px] text-[var(--binary-outline)]"
                                        >
                                            {{ effectiveUser.email }}
                                        </p>
                                    </div>
                                </div>
                                <button
                                    v-if="!effectiveUser.has_google_account"
                                    type="button"
                                    class="binary-label w-full rounded-lg px-3 py-2 text-left text-xs text-[var(--binary-text)] uppercase transition hover:bg-[var(--binary-surface-container)]"
                                    @click="bindGoogle"
                                >
                                    {{ t('layout.bind_google') }}
                                </button>
                                <!-- 僅未驗證 email 的登入使用者顯示重寄驗證信 -->
                                <button
                                    v-if="!effectiveUser.email_verified_at"
                                    type="button"
                                    class="binary-label w-full rounded-lg px-3 py-2 text-left text-xs text-[var(--binary-primary)] uppercase transition hover:bg-[var(--binary-surface-container)]"
                                    @click="resendVerification"
                                >
                                    重寄驗證信
                                </button>
                                <a
                                    v-if="isAdmin"
                                    :href="routes.admin.system()"
                                    class="binary-label mt-1 block w-full rounded-lg px-3 py-2 text-left text-xs text-[var(--binary-primary)] uppercase transition hover:bg-[var(--binary-surface-container)]"
                                >
                                    {{ t('layout.admin_system') }}
                                </a>
                                <a
                                    :href="routes.profile()"
                                    class="binary-label mt-1 block w-full rounded-lg px-3 py-2 text-left text-xs text-[var(--binary-text)] uppercase transition hover:bg-[var(--binary-surface-container)]"
                                >
                                    {{ t('layout.account_settings') }}
                                </a>
                                <button
                                    type="button"
                                    :disabled="isLoggingOut"
                                    class="binary-label mt-1 w-full rounded-lg px-3 py-2 text-left text-xs text-[var(--binary-text)] uppercase transition hover:bg-[var(--binary-surface-container)] disabled:opacity-50"
                                    @click="logout"
                                >
                                    {{
                                        isLoggingOut
                                            ? t('layout.logging_out')
                                            : t('layout.logout')
                                    }}
                                </button>
                            </div>
                        </details>
                    </template>
                    <template v-else>
                        <Link
                            class="binary-ghost-button hidden sm:inline-flex"
                            :href="routes.login()"
                            >{{ t('layout.login') }}</Link
                        >
                        <Link
                            class="binary-display rounded-md px-6 py-2 text-xs font-bold text-[var(--binary-on-primary-container)] uppercase"
                            :href="routes.register()"
                            style="
                                background: linear-gradient(
                                    145deg,
                                    var(--binary-primary) 0%,
                                    var(--binary-primary-container) 100%
                                );
                            "
                        >
                            {{ t('layout.register') }}
                        </Link>
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
        <slot />
    </div>
</template>
