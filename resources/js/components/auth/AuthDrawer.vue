<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { useAuth } from '../../composables/useAuth';
import { logoutWithApi } from '../../lib/auth-api';
import { api, routes } from '../../lib/routes';
import LoginForm from './LoginForm.vue';
import ProfilePanel from './ProfilePanel.vue';
import RegisterForm from './RegisterForm.vue';

interface Props {
    open: boolean;
    tab: 'login' | 'register' | 'profile';
}

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [boolean];
    'update:tab': ['login' | 'register' | 'profile'];
}>();

const { user } = useAuth();
const { t } = useI18n();
const page = usePage();
const isLoggingOut = ref(false);

// 後台關閉「開放使用者自行註冊」時，註冊分頁改顯示提示、社群區提醒僅限既有帳號。
const allowRegistration = computed(
    () => page.props.allowRegistration !== false,
);

function close() {
    emit('update:open', false);
}

function setTab(tab: 'login' | 'register' | 'profile') {
    emit('update:tab', tab);
}

async function logout() {
    if (isLoggingOut.value) {
        return;
    }

    isLoggingOut.value = true;

    try {
        await logoutWithApi();
        close();
        router.visit(routes.home(), { replace: true });
    } finally {
        isLoggingOut.value = false;
    }
}

function bindGoogle() {
    window.location.href = api.auth.googleRedirect();
}

const onKey = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.open) {
        close();
    }
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition name="auth-fade">
            <div
                v-if="open"
                class="fixed inset-x-0 top-16 bottom-0 z-[60] bg-black/50 backdrop-blur-sm"
                @click="close"
            />
        </Transition>

        <!-- Drawer panel -->
        <Transition name="auth-slide">
            <div
                v-if="open"
                class="fixed top-16 right-0 bottom-0 z-[70] flex"
                style="width: 440px"
            >
                <!-- Vertical tab strip -->
                <div
                    class="flex w-14 flex-col bg-[var(--binary-surface-dim)] py-10"
                >
                    <template v-if="!user">
                        <!-- Guest tabs -->
                        <div class="flex flex-col items-center gap-6 pt-2">
                            <button
                                class="auth-vtab"
                                :class="{ active: tab === 'login' }"
                                @click="setTab('login')"
                            >
                                LOGIN
                            </button>
                            <button
                                class="auth-vtab"
                                :class="{ active: tab === 'register' }"
                                @click="setTab('register')"
                            >
                                REGISTER
                            </button>
                        </div>
                    </template>

                    <template v-else>
                        <!-- Profile label -->
                        <div class="flex flex-col items-center gap-6 pt-2">
                            <span class="auth-vtab-label">PROFILE</span>

                            <!-- Sub-options -->
                            <div
                                class="mt-2 flex w-full flex-col items-center gap-4 border-t border-[var(--binary-outline-variant)]/30 pt-4"
                            >
                                <button
                                    v-if="!user.has_google_account"
                                    class="auth-vtab"
                                    type="button"
                                    @click="bindGoogle"
                                >
                                    {{ t('layout.bind_google') }}
                                </button>
                                <button
                                    class="auth-vtab"
                                    :class="{ 'opacity-50': isLoggingOut }"
                                    type="button"
                                    :disabled="isLoggingOut"
                                    @click="logout"
                                >
                                    {{
                                        isLoggingOut
                                            ? t('layout.logging_out')
                                            : t('layout.logout')
                                    }}
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Content area -->
                <div
                    class="flex-1 overflow-y-auto bg-[var(--binary-surface)] px-8 py-10"
                >
                    <p
                        v-if="!user"
                        class="binary-label mb-6 text-xs font-bold text-[var(--binary-primary)] uppercase"
                    >
                        {{
                            tab === 'login'
                                ? '> TERMINAL_UPLINK'
                                : '> NEW_ACCOUNT'
                        }}
                    </p>
                    <p
                        v-else
                        class="binary-label mb-6 text-xs font-bold text-[var(--binary-primary)] uppercase"
                    >
                        > ACCOUNT_CENTER
                    </p>

                    <LoginForm v-if="tab === 'login' && !user" />
                    <template v-else-if="tab === 'register' && !user">
                        <RegisterForm v-if="allowRegistration" />
                        <p
                            v-else
                            class="text-sm leading-6 text-[var(--binary-text-muted)]"
                        >
                            {{ t('auth.registration_closed') }}
                        </p>
                    </template>
                    <ProfilePanel v-else-if="user" />

                    <!-- Social buttons: guest only -->
                    <div v-if="!user" class="mt-6 flex flex-col gap-3">
                        <p
                            v-if="!allowRegistration"
                            class="text-xs leading-5 text-[var(--binary-outline)]"
                        >
                            {{ t('auth.registration_closed_oauth_hint') }}
                        </p>
                        <button
                            type="button"
                            class="binary-ghost-button flex w-full items-center justify-center gap-3"
                        >
                            <!-- GitHub mark -->
                            <svg
                                class="h-4 w-4 shrink-0"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                            >
                                <path
                                    d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"
                                />
                            </svg>
                            GitHub
                        </button>
                        <a
                            class="binary-ghost-button flex w-full items-center justify-center gap-3"
                            href="/api/auth/google/redirect"
                        >
                            <!-- Google G -->
                            <svg
                                class="h-4 w-4 shrink-0"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                            >
                                <path
                                    d="M21.805 10.023H12v3.955h5.618c-.242 1.285-.976 2.373-2.078 3.103v2.58h3.362c1.966-1.81 3.1-4.476 3.1-7.647 0-.744-.067-1.462-.197-2.156-.001.001-.001.165 0 .165z"
                                    opacity=".9"
                                />
                                <path
                                    d="M12 22c2.7 0 4.963-.895 6.617-2.42l-3.362-2.579c-.896.6-2.04.953-3.255.953-2.504 0-4.625-1.69-5.382-3.963H3.15v2.661C4.793 19.89 8.157 22 12 22z"
                                    opacity=".9"
                                />
                                <path
                                    d="M6.618 13.991A5.98 5.98 0 0 1 6.25 12c0-.692.12-1.365.368-1.991V7.348H3.15A9.98 9.98 0 0 0 2 12c0 1.613.387 3.138 1.15 4.652l3.468-2.661z"
                                    opacity=".9"
                                />
                                <path
                                    d="M12 6.046c1.41 0 2.676.485 3.674 1.437l2.754-2.754C16.956 3.198 14.692 2 12 2 8.157 2 4.793 4.11 3.15 7.348l3.468 2.661C7.375 7.736 9.496 6.046 12 6.046z"
                                    opacity=".9"
                                />
                            </svg>
                            Google
                        </a>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style>
.auth-vtab {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    font-family: var(--font-binary, monospace);
    color: var(--binary-outline);
    cursor: pointer;
    padding: 10px 6px;
    border-right: 2px solid transparent;
    transition:
        color 0.15s,
        border-color 0.15s;
    text-decoration: none;
}

.auth-vtab:hover {
    color: var(--binary-text);
}

.auth-vtab.active {
    color: var(--binary-primary);
    border-right-color: var(--binary-primary);
}

.auth-vtab-label {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    font-family: var(--font-binary, monospace);
    color: var(--binary-primary);
    padding: 10px 6px;
}

.auth-slide-enter-from,
.auth-slide-leave-to {
    transform: translateX(100%);
}

.auth-slide-enter-active {
    transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
}

.auth-slide-leave-active {
    transition: transform 0.22s ease-in;
}

.auth-fade-enter-from,
.auth-fade-leave-to {
    opacity: 0;
}

.auth-fade-enter-active,
.auth-fade-leave-active {
    transition: opacity 0.2s ease;
}
</style>
