<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

import NavIcon from './NavIcon.vue';

interface NavChild {
    label: string;
    href: string;
    active?: boolean;
    icon?: string;
}

interface NavLink {
    label: string;
    href?: string;
    active?: boolean;
    icon?: string;
    children?: NavChild[];
}

const props = defineProps<{
    open: boolean;
    links: NavLink[];
}>();

const emit = defineEmits<{
    'update:open': [boolean];
}>();

// accordion：同時只展開一個有子項的區段
const openSection = ref<string | null>(null);

function close() {
    emit('update:open', false);
}

function toggleSection(label: string) {
    openSection.value = openSection.value === label ? null : label;
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
        <!-- Backdrop（行動版限定） -->
        <Transition name="nav-fade">
            <div
                v-if="open"
                class="fixed inset-x-0 top-16 bottom-0 z-[60] bg-black/50 backdrop-blur-sm md:hidden"
                @click="close"
            />
        </Transition>

        <!-- 左側滑入面板 -->
        <Transition name="nav-slide">
            <aside
                v-if="open"
                class="fixed top-16 bottom-0 left-0 z-[70] w-[min(300px,80vw)] overflow-y-auto bg-[var(--binary-surface-dim)] px-4 py-6 md:hidden"
            >
                <nav class="flex flex-col gap-1">
                    <template v-for="link in links" :key="link.label">
                        <!-- 有子項：accordion -->
                        <div v-if="link.children">
                            <button
                                type="button"
                                class="binary-label flex w-full items-center justify-between rounded-lg px-3 py-3 text-xs text-[var(--binary-text)] uppercase transition hover:bg-[var(--binary-surface-container)]"
                                :class="
                                    link.active
                                        ? 'text-[var(--binary-primary)]'
                                        : ''
                                "
                                @click="toggleSection(link.label)"
                            >
                                <span class="flex items-center gap-2">
                                    <NavIcon
                                        v-if="link.icon"
                                        :name="link.icon"
                                    />
                                    {{ link.label }}
                                </span>
                                <svg
                                    class="h-3 w-3 opacity-60 transition-transform"
                                    :class="
                                        openSection === link.label
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
                                v-if="openSection === link.label"
                                class="mt-1 ml-4 flex flex-col gap-1 border-l border-[var(--binary-outline-variant)] pl-3"
                            >
                                <a
                                    v-for="child in link.children"
                                    :key="child.href"
                                    :href="child.href"
                                    class="binary-label flex items-center gap-2 rounded-lg px-3 py-2.5 text-xs text-[var(--binary-text)] uppercase transition hover:bg-[var(--binary-surface-container)] hover:text-[var(--binary-primary)]"
                                    :class="
                                        child.active
                                            ? 'text-[var(--binary-primary)]'
                                            : ''
                                    "
                                    @click="close"
                                >
                                    <NavIcon
                                        v-if="child.icon"
                                        :name="child.icon"
                                    />
                                    {{ child.label }}
                                </a>
                            </div>
                        </div>

                        <!-- 直接連結 -->
                        <a
                            v-else
                            :href="link.href"
                            class="binary-label flex items-center gap-2 rounded-lg px-3 py-3 text-xs text-[var(--binary-text)] uppercase transition hover:bg-[var(--binary-surface-container)] hover:text-[var(--binary-primary)]"
                            :class="
                                link.active
                                    ? 'text-[var(--binary-primary)]'
                                    : ''
                            "
                            @click="close"
                        >
                            <NavIcon v-if="link.icon" :name="link.icon" />
                            {{ link.label }}
                        </a>
                    </template>
                </nav>
            </aside>
        </Transition>
    </Teleport>
</template>

<style scoped>
.nav-slide-enter-from,
.nav-slide-leave-to {
    transform: translateX(-100%);
}

.nav-slide-enter-active {
    transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
}

.nav-slide-leave-active {
    transition: transform 0.22s ease-in;
}

.nav-fade-enter-from,
.nav-fade-leave-to {
    opacity: 0;
}

.nav-fade-enter-active,
.nav-fade-leave-active {
    transition: opacity 0.2s ease;
}
</style>
