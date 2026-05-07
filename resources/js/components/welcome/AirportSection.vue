<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { routes } from '../../lib/routes';
</script>

<template>
    <section id="airports" class="bg-[linear-gradient(145deg,var(--binary-primary)_0%,var(--binary-primary-container)_100%)] px-6 py-24 md:px-8">
        <div class="mx-auto flex max-w-screen-2xl flex-col items-center text-center text-[var(--binary-on-primary-container)]">
            <span class="binary-label mb-8 text-xs uppercase tracking-[0.4em]">Airport Intelligence</span>
            <h2 class="binary-display mb-6 text-5xl font-black uppercase leading-none tracking-tight md:text-8xl">
                GLOBAL
                <br>
                AIRPORT_MAP
            </h2>
            <p class="mb-12 max-w-2xl text-base text-[color:rgba(0,113,23,0.85)] md:text-lg">
                進入 Airports 頁面可使用搜尋、統計與地球視圖，點擊國家即可取得該國機場並在地球上顯示大頭針。
            </p>

            <div class="mt-2 flex flex-col justify-center gap-4 sm:flex-row">
                <Link class="rounded-md bg-[var(--binary-surface)] px-8 py-4 binary-display text-sm font-bold uppercase text-[var(--binary-primary)]" :href="routes.airports()">
                    Open Airports
                </Link>
                <Link class="rounded-md px-8 py-4 binary-display text-sm font-bold uppercase text-[var(--binary-on-primary-container)]" :href="routes.register()" style="background-color: rgba(15, 21, 17, 0.08);">
                    Create Node
                </Link>
            </div>
        </div>
    </section>
</template>
