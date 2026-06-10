<script setup lang="ts">
import { computed } from 'vue';
import { THEME_REGISTRY, useTheme } from '../../composables/useTheme';
import { useThemeCardEffect } from '../../composables/useThemeCardEffect';
import { galleryProjects } from '../../data/projects';
import type { Project } from '../../data/projects';

const { theme } = useTheme();
useThemeCardEffect();

const props = defineProps<{
    featuredProjects: Project[];
}>();

const projects = computed(() => [
    ...props.featuredProjects,
    ...galleryProjects,
]);
</script>

<template>
    <section id="projects" class="binary-section bg-[var(--binary-surface)]">
        <div class="mx-auto max-w-screen-2xl">
            <div
                class="mb-8 flex flex-col gap-4 md:mb-16 md:flex-row md:items-baseline md:justify-between"
            >
                <h2
                    class="binary-display text-2xl font-black tracking-tight uppercase md:text-6xl"
                >
                    Featured Projects
                </h2>
                <div
                    class="binary-label text-sm tracking-[0.2em] text-[var(--binary-outline)] uppercase"
                >
                    &gt; view_all_repository
                </div>
            </div>

            <div class="flex flex-col gap-6">
                <div
                    v-for="(project, index) in projects"
                    :key="project.id"
                    class="relative"
                >
                    <article
                        class="binary-card relative flex w-full flex-col gap-6 transition-opacity md:items-center md:gap-12"
                        :class="[
                            THEME_REGISTRY[theme].cardClass,
                            (index + 1) % 2 === 1
                                ? 'md:flex-row'
                                : 'md:flex-row-reverse',
                            project.status === 'paused'
                                ? 'opacity-40 grayscale'
                                : '',
                        ]"
                    >
                        <div class="w-full md:w-1/2">
                            <span
                                class="binary-label mb-4 block text-xs font-bold text-[var(--binary-primary)]"
                                >{{ project.id }} / {{ project.category }}</span
                            >
                            <h3
                                class="binary-display mb-4 text-xl font-bold uppercase md:mb-6 md:text-5xl"
                            >
                                {{ project.title }}
                            </h3>
                            <ol
                                v-if="Array.isArray(project.description)"
                                class="mb-5 space-y-3 text-xs leading-relaxed text-[var(--binary-text-muted)] md:mb-8 md:text-base"
                            >
                                <li
                                    v-for="(item, i) in project.description"
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
                                class="mb-5 text-xs leading-relaxed text-[var(--binary-text-muted)] md:mb-8 md:text-lg"
                            >
                                {{ project.description }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="tag in project.tags"
                                    :key="`${project.id}-${tag}`"
                                    class="binary-chip binary-label text-[9px] uppercase"
                                >
                                    &gt; {{ tag }}
                                </span>
                            </div>
                        </div>

                        <div v-if="project.image" class="w-full md:w-1/2">
                            <img
                                :src="project.image"
                                :alt="`${project.title} preview`"
                                class="h-full min-h-[280px] w-full rounded-none object-cover md:rounded-2xl"
                            />
                        </div>

                        <!-- Commit log panel -->
                        <div
                            v-else-if="project.commits"
                            class="binary-label w-full rounded-none bg-[var(--binary-surface-lowest)] p-4 text-[10px] md:w-1/2 md:rounded-2xl md:p-6 md:text-xs"
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
                                    {{ project.id }}/main</span
                                >
                            </div>
                            <div
                                v-for="commit in project.commits"
                                :key="commit.hash"
                                class="mb-3 md:flex md:items-start md:gap-3"
                            >
                                <div
                                    class="flex items-center gap-3 md:contents"
                                >
                                    <span
                                        class="shrink-0 font-mono text-[var(--binary-primary)] opacity-70"
                                        >{{ commit.hash }}</span
                                    >
                                    <span
                                        class="shrink-0 text-[var(--binary-outline)]"
                                        >{{ commit.date }}</span
                                    >
                                </div>
                                <span
                                    class="block leading-relaxed break-words text-[var(--binary-text-muted)] md:min-w-0"
                                    >{{ commit.message }}</span
                                >
                                <span
                                    v-if="commit.tag"
                                    class="mt-1 inline-block shrink-0 rounded bg-[var(--binary-primary)]/10 px-1.5 py-0.5 text-[9px] text-[var(--binary-primary)] uppercase md:mt-0 md:ml-auto"
                                    >{{ commit.tag }}</span
                                >
                            </div>
                        </div>

                        <!-- Fallback terminal panel -->
                        <div
                            v-else
                            class="binary-label w-full rounded-none bg-[var(--binary-surface-lowest)] p-4 text-sm md:w-1/2 md:rounded-2xl md:p-6"
                            style="
                                box-shadow: inset 4px 0 0 0
                                    var(--binary-primary);
                            "
                        >
                            <div class="mb-2 flex gap-4">
                                <span class="text-[var(--binary-outline)]"
                                    >01</span
                                >
                                <span class="text-[var(--binary-primary)]"
                                    >&gt; project: {{ project.id }}</span
                                >
                            </div>
                            <div class="mb-2 flex gap-4">
                                <span class="text-[var(--binary-outline)]"
                                    >02</span
                                >
                                <span class="pl-4 text-[var(--binary-text)]"
                                    >&gt; domain: {{ project.category }}</span
                                >
                            </div>
                            <div
                                v-for="(tag, tagIndex) in project.tags"
                                :key="`${project.id}-panel-${tag}`"
                                class="mb-2 flex gap-4"
                            >
                                <span class="text-[var(--binary-outline)]">{{
                                    String(tagIndex + 3).padStart(2, '0')
                                }}</span>
                                <span
                                    class="pl-4 text-[var(--binary-text-muted)]"
                                    >&gt; stack: {{ tag }}</span
                                >
                            </div>
                            <div class="mt-2 flex gap-4">
                                <span class="text-[var(--binary-outline)]">{{
                                    String(project.tags.length + 3).padStart(
                                        2,
                                        '0',
                                    )
                                }}</span>
                                <span class="text-[var(--binary-primary)]"
                                    >status:
                                    {{ project.status ?? 'online' }}</span
                                >
                            </div>
                            <a
                                v-if="project.link"
                                :href="project.link"
                                class="mt-5 inline-flex items-center gap-2 rounded-lg border border-[var(--binary-primary)]/40 px-4 py-2 text-[11px] text-[var(--binary-primary)] uppercase transition hover:bg-[var(--binary-primary)]/10"
                                >&gt; visit_project</a
                            >
                        </div>
                        <div
                            class="glow rounded-inherit pointer-events-none absolute inset-0 z-10"
                        ></div>
                        <canvas
                            class="border-canvas pointer-events-none absolute inset-0 z-10"
                        ></canvas>
                    </article>
                </div>
            </div>
        </div>
    </section>
</template>
