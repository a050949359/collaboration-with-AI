<script setup lang="ts">
// PoC：Projects 區塊 scrollytelling（桌機）
// 釘住兩欄框，往下滑時用 anime.js v4 讓「圖＋文」一起 crossfade 換成下一個專案。
// 手機由父層改用 ProjectsSection（堆疊卡片）fallback，本元件僅在 md+ 顯示。
import { useEventListener } from '@vueuse/core';
import { animate } from 'animejs';
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import type { Project } from '../../data/projects';

const props = defineProps<{ projects: Project[] }>();

// 每個專案佔的捲動距離（vh）；越小切換越快
const VH_PER_PROJECT = 65;

const rootRef = ref<HTMLElement | null>(null);
const imgRef = ref<HTMLElement | null>(null);
const textRef = ref<HTMLElement | null>(null);
const activeIndex = ref(0);

const total = computed(() => props.projects.length);
const active = computed(() => props.projects[activeIndex.value]);

// scroll 進度 → activeIndex（每個專案約佔一個視窗高度的捲動距離）
function recompute() {
    const root = rootRef.value;

    if (!root) {
        return;
    }

    const totalScroll = root.offsetHeight - window.innerHeight;

    if (totalScroll <= 0) {
        return; // 隱藏（手機）或量不到高度時略過
    }

    const scrolled = Math.min(
        Math.max(-root.getBoundingClientRect().top, 0),
        totalScroll,
    );
    const idx = Math.min(
        Math.max(Math.round((scrolled / totalScroll) * (total.value - 1)), 0),
        total.value - 1,
    );

    if (idx !== activeIndex.value) {
        activeIndex.value = idx;
    }
}

useEventListener(window, 'scroll', recompute, { passive: true });
useEventListener(window, 'resize', recompute);
onMounted(recompute);

// activeIndex 變更 → 圖＋文一起淡入（圖微縮放、文微上移）
watch(activeIndex, async () => {
    await nextTick();

    if (imgRef.value) {
        animate(imgRef.value, {
            opacity: [0, 1],
            scale: [1.04, 1],
            duration: 520,
            ease: 'outExpo',
        });
    }

    if (textRef.value) {
        animate(textRef.value, {
            opacity: [0, 1],
            translateY: [16, 0],
            duration: 460,
            delay: 60,
            ease: 'outExpo',
        });
    }
});

// 點進度點 → 捲到對應專案
function jumpTo(i: number) {
    const root = rootRef.value;

    if (!root) {
        return;
    }

    const totalScroll = root.offsetHeight - window.innerHeight;
    const top = root.offsetTop + (i / (total.value - 1)) * totalScroll;
    window.scrollTo({ top, behavior: 'smooth' });
}
</script>

<template>
    <section
        ref="rootRef"
        class="relative bg-[var(--binary-surface)]"
        :style="{ height: total * VH_PER_PROJECT + 'vh' }"
    >
        <div class="sticky top-16 flex h-[calc(100vh-4rem)] items-center">
            <div
                class="relative mx-auto grid w-full max-w-screen-2xl grid-cols-12 items-center gap-10 px-8"
            >
                <!-- 文字側 -->
                <div ref="textRef" class="col-span-5 col-start-1 min-w-0">
                    <span
                        class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]"
                        >{{ active.id }} / {{ active.category }}</span
                    >
                    <h3
                        class="binary-display mb-6 text-4xl font-bold uppercase md:text-5xl"
                    >
                        {{ active.title }}
                    </h3>
                    <ol
                        v-if="Array.isArray(active.description)"
                        class="mb-8 space-y-3 text-sm leading-relaxed text-[var(--binary-text-muted)] md:text-base"
                    >
                        <li
                            v-for="(item, i) in active.description"
                            :key="i"
                            class="flex gap-3"
                        >
                            <span
                                class="binary-label shrink-0 text-[var(--binary-primary)]"
                                >{{ i + 1 }}.</span
                            >
                            <span>{{ item }}</span>
                        </li>
                    </ol>
                    <p
                        v-else
                        class="mb-8 text-base leading-relaxed text-[var(--binary-text-muted)]"
                    >
                        {{ active.description }}
                    </p>
                    <div class="mb-6 flex flex-wrap gap-2">
                        <span
                            v-for="tag in active.tags"
                            :key="`${active.id}-${tag}`"
                            class="binary-chip binary-label text-[9px] uppercase"
                        >
                            &gt; {{ tag }}
                        </span>
                    </div>
                    <a
                        v-if="active.link"
                        :href="active.link"
                        class="inline-flex items-center gap-2 rounded-lg border border-[var(--binary-primary)]/40 px-4 py-2 text-[11px] text-[var(--binary-primary)] uppercase transition hover:bg-[var(--binary-primary)]/10"
                        >&gt; visit_project</a
                    >
                </div>

                <!-- 視覺側（圖；01 無圖則顯示 commit log） -->
                <div class="col-span-6 col-start-7 min-w-0">
                    <img
                        v-if="active.image"
                        ref="imgRef"
                        :src="active.image"
                        :alt="`${active.title} preview`"
                        class="max-h-[70vh] w-full rounded-2xl object-cover"
                    />
                    <div
                        v-else-if="active.commits"
                        ref="imgRef"
                        class="binary-label max-h-[70vh] w-full overflow-y-auto rounded-2xl bg-[var(--binary-surface-lowest)] p-6 text-xs"
                        style="
                            box-shadow: inset 4px 0 0 0 var(--binary-primary);
                        "
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-[var(--binary-outline)]"
                        >
                            <span class="text-[var(--binary-primary)]">*</span>
                            <span>git log --oneline {{ active.id }}/main</span>
                        </div>
                        <div
                            v-for="commit in active.commits"
                            :key="commit.hash"
                            class="mb-3 flex items-start gap-3"
                        >
                            <span
                                class="shrink-0 font-mono text-[var(--binary-primary)] opacity-70"
                                >{{ commit.hash }}</span
                            >
                            <span
                                class="block leading-relaxed break-words text-[var(--binary-text-muted)]"
                                >{{ commit.message }}</span
                            >
                        </div>
                    </div>
                </div>

                <!-- 進度點 -->
                <div
                    class="absolute top-1/2 right-2 flex -translate-y-1/2 flex-col gap-2"
                >
                    <button
                        v-for="(p, i) in projects"
                        :key="p.id"
                        type="button"
                        :aria-label="`前往 ${p.title}`"
                        class="h-2 w-2 rounded-full transition-all"
                        :class="
                            i === activeIndex
                                ? 'scale-125 bg-[var(--binary-primary)]'
                                : 'bg-[var(--binary-outline)]/40 hover:bg-[var(--binary-outline)]'
                        "
                        @click="jumpTo(i)"
                    />
                </div>
            </div>
        </div>
    </section>
</template>
