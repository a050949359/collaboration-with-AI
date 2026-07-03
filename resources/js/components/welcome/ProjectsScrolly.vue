<script setup lang="ts">
// PoC：Projects 區塊 scrollytelling（桌機）
// 釘住兩欄框，往下滑時用 anime.js v4 讓「圖＋文」crossfade 換成下一個專案：
// - 奇偶專案左右交錯 + 上下錯位，滑入方向跟隨捲動方向
// - 文字逐項 stagger、tags 瀑布式進場、圖片隨捲動微視差
// - 背景超大編號以里程表（odometer）方式逐位滾動切換
// 手機由父層改用 ProjectsSection（堆疊卡片）fallback，本元件僅在 md+ 顯示。
import { useEventListener } from '@vueuse/core';
import { animate, stagger } from 'animejs';
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import type { Project } from '../../data/projects';

const props = defineProps<{ projects: Project[] }>();

// 每個專案佔的捲動距離（vh）；越小切換越快
const VH_PER_PROJECT = 65;

const rootRef = ref<HTMLElement | null>(null);
const imgRef = ref<HTMLElement | null>(null);
const textRef = ref<HTMLElement | null>(null);
const stripRefs = ref<(HTMLElement | null)[]>([]);
const activeIndex = ref(0);
// 目前專案在自身捲動區間內的偏移（-0.5〜0.5）→ 圖片微視差
const parallax = ref(0);

const total = computed(() => props.projects.length);
// projects 為空時回 null，template 以 v-if 跳過渲染
const active = computed<Project | null>(
    () => props.projects[activeIndex.value] ?? null,
);
// 奇偶交錯：偶數 index 文字在左、圖在右，奇數相反
const isEven = computed(() => activeIndex.value % 2 === 0);
const digits = computed(() => active.value?.id.split('') ?? []);

function setStripRef(el: unknown, i: number) {
    // 元素卸載時 Vue 會傳 null → 清除舊引用
    stripRefs.value[i] = el instanceof HTMLElement ? el : null;
}

// 背景大編號逐位滾動（odometer）到目前專案的 id
function rollDigits() {
    digits.value.forEach((d, i) => {
        const el = stripRefs.value[i];
        const num = Number(d);

        if (el) {
            animate(el, {
                // 非數字字元回退至 0，避免 translateY(NaNem)
                translateY: `${Number.isNaN(num) ? 0 : -num}em`,
                duration: 800,
                ease: 'outExpo',
            });
        }
    });
}

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
    const exact = (scrolled / totalScroll) * (total.value - 1);
    const idx = Math.min(Math.max(Math.round(exact), 0), total.value - 1);

    // 距離目前專案中心的偏移量 → 圖片微視差
    parallax.value = (exact - idx) * -28;

    if (idx !== activeIndex.value) {
        activeIndex.value = idx;
    }
}

useEventListener(window, 'scroll', recompute, { passive: true });
useEventListener(window, 'resize', recompute);
onMounted(() => {
    recompute();
    rollDigits();
});

// activeIndex 變更 → 依捲動方向滑入：文字逐項 stagger、tags 瀑布、圖微縮放
watch(activeIndex, async (newIdx, oldIdx) => {
    const dir = newIdx > oldIdx ? 1 : -1;

    await nextTick();

    if (imgRef.value) {
        animate(imgRef.value, {
            opacity: [0, 1],
            translateY: [dir * 40, 0],
            scale: [1.04, 1],
            duration: 620,
            ease: 'outExpo',
        });
    }

    if (textRef.value) {
        animate(
            textRef.value.querySelectorAll<HTMLElement>(
                ':scope > *:not([data-tags])',
            ),
            {
                opacity: [0, 1],
                translateY: [dir * 28, 0],
                duration: 520,
                delay: stagger(70),
                ease: 'outExpo',
            },
        );
        animate(
            textRef.value.querySelectorAll<HTMLElement>('[data-tags] > *'),
            {
                opacity: [0, 1],
                translateY: [dir * 16, 0],
                duration: 420,
                delay: stagger(40, { start: 180 }),
                ease: 'outExpo',
            },
        );
    }

    rollDigits();
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
        <div
            v-if="active"
            class="sticky top-16 flex h-[calc(100vh-4rem)] items-center overflow-hidden"
        >
            <!-- 背景大編號（odometer 逐位滾動）：釘在固定角落，不隨奇偶換邊 -->
            <div
                aria-hidden="true"
                class="binary-display pointer-events-none absolute top-8 left-8 flex overflow-hidden text-[10rem] leading-none font-bold select-none"
                style="
                    height: 1em;
                    color: color-mix(
                        in srgb,
                        var(--binary-primary) 12%,
                        transparent
                    );
                "
            >
                <div
                    v-for="i in digits.length"
                    :key="i"
                    :ref="(el) => setStripRef(el, i - 1)"
                    class="flex flex-col"
                >
                    <span v-for="n in 10" :key="n" class="h-[1em]">{{
                        n - 1
                    }}</span>
                </div>
            </div>
            <div
                class="relative mx-auto grid w-full max-w-screen-2xl grid-cols-12 items-center gap-10 px-8"
            >
                <!-- 文字側（奇偶交錯 + 垂直錯位） -->
                <div
                    class="relative col-span-5 row-start-1 min-w-0 transition-transform duration-700 ease-out"
                    :class="
                        isEven
                            ? 'col-start-1 -translate-y-8'
                            : 'col-start-8 translate-y-8'
                    "
                >
                    <div ref="textRef" class="relative">
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
                        <div data-tags class="mb-6 flex flex-wrap gap-2">
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
                </div>

                <!-- 視覺側（圖；01 無圖則顯示 commit log）（奇偶交錯 + 反向錯位） -->
                <div
                    class="col-span-6 row-start-1 min-w-0 transition-transform duration-700 ease-out"
                    :class="
                        isEven
                            ? 'col-start-7 translate-y-8'
                            : 'col-start-1 -translate-y-8'
                    "
                >
                    <!-- 視差層：跟著捲動微幅位移，與 anime 動畫分離 -->
                    <div :style="{ transform: `translateY(${parallax}px)` }">
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
                                box-shadow: inset 4px 0 0 0
                                    var(--binary-primary);
                            "
                        >
                            <div
                                class="mb-4 flex items-center gap-2 text-[var(--binary-outline)]"
                            >
                                <span class="text-[var(--binary-primary)]"
                                    >*</span
                                >
                                <span
                                    >git log --oneline
                                    {{ active.id }}/main</span
                                >
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
