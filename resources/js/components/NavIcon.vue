<script setup lang="ts">
defineProps<{ name: string; size?: number }>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :width="size ?? 16"
        :height="size ?? 16"
        fill="none"
        class="nav-icon flex-shrink-0"
        aria-hidden="true"
    >
        <!-- home -->
        <template v-if="name === 'home'">
            <path
                class="stroke-default"
                d="M5 12 L5 24 a2 2 0 0 0 2 2 h18 a2 2 0 0 0 2 -2 V14 a2 2 0 0 0 -2 -2 H16 L13 8 H7 a2 2 0 0 0 -2 2 V12"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <path
                class="stroke-default anim-folder"
                d="M9 16 H23"
                stroke-width="1.5"
                stroke-linecap="round"
            />
        </template>

        <!-- articles -->
        <template v-else-if="name === 'articles'">
            <path
                class="stroke-default"
                d="M7 5 H21 L25 9 V25 a2 2 0 0 1 -2 2 H9 a2 2 0 0 1 -2 -2 Z"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <path
                class="stroke-default"
                d="M21 5 V9 H25"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <path
                class="stroke-default anim-art-1"
                d="M11 14 H21"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <path
                class="stroke-default anim-art-2"
                d="M11 18 H21"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <path
                class="stroke-default anim-art-3"
                d="M11 22 H17"
                stroke-width="1.5"
                stroke-linecap="round"
            />
        </template>

        <!-- aviation -->
        <template v-else-if="name === 'aviation'">
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="2.4"
                stroke-width="1.5"
            />
            <g class="anim-prop">
                <path
                    class="stroke-default"
                    d="M16 14 C 16 9, 11 6, 9 8 C 11 10, 14 13, 16 14 Z"
                    stroke-width="1.5"
                    stroke-linejoin="round"
                />
                <path
                    class="stroke-default"
                    d="M18 16 C 23 16, 26 21, 24 23 C 22 21, 19 18, 18 16 Z"
                    stroke-width="1.5"
                    stroke-linejoin="round"
                />
            </g>
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="11"
                stroke-width="1.5"
                opacity="0.3"
            />
        </template>

        <!-- airports -->
        <template v-else-if="name === 'airports'">
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="11"
                stroke-width="1.5"
            />
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="6"
                stroke-width="1.5"
                opacity="0.5"
            />
            <circle class="fill-default" cx="16" cy="16" r="1.2" />
            <path
                class="stroke-default anim-radar-arm"
                d="M16 16 L16 5"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <circle
                class="fill-default anim-radar-blip"
                cx="22"
                cy="11"
                r="1.4"
            />
        </template>

        <!-- airlines -->
        <template v-else-if="name === 'airlines'">
            <path
                class="stroke-default anim-trail"
                d="M5 22 Q 12 18 17 14"
                stroke-width="1.5"
                stroke-linecap="round"
                opacity="0.5"
            />
            <g class="anim-plane">
                <path
                    class="stroke-default"
                    d="M27 6 L13 14 L18 17 L21 22 Z"
                    stroke-width="1.5"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />
                <path
                    class="stroke-default"
                    d="M18 17 L20 12"
                    stroke-width="1.5"
                    stroke-linecap="round"
                />
            </g>
        </template>

        <!-- countries -->
        <template v-else-if="name === 'countries'">
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="10"
                stroke-width="1.5"
            />
            <g class="anim-globe-rot">
                <ellipse
                    class="stroke-default"
                    cx="16"
                    cy="16"
                    rx="10"
                    ry="3.4"
                    stroke-width="1.5"
                />
                <ellipse
                    class="stroke-default"
                    cx="16"
                    cy="16"
                    rx="3.4"
                    ry="10"
                    stroke-width="1.5"
                />
                <line
                    class="stroke-default"
                    x1="16"
                    y1="6"
                    x2="16"
                    y2="26"
                    stroke-width="1.5"
                    opacity="0.5"
                />
            </g>
            <ellipse
                class="stroke-default anim-orbit"
                cx="16"
                cy="16"
                rx="13"
                ry="4.4"
                stroke-width="1"
                stroke-dasharray="2 2"
                opacity="0.45"
            />
            <circle
                class="fill-default anim-orbit-dot"
                cx="16"
                cy="16"
                r="1.6"
            />
        </template>

        <!-- about -->
        <template v-else-if="name === 'about'">
            <circle
                class="stroke-default anim-info-ring"
                cx="16"
                cy="16"
                r="11"
                stroke-width="1.5"
            />
            <circle
                class="fill-default anim-info-dot"
                cx="16"
                cy="10"
                r="1.4"
            />
            <path
                class="stroke-default"
                d="M16 14 V22"
                stroke-width="1.5"
                stroke-linecap="round"
            />
        </template>

        <!-- linebot -->
        <template v-else-if="name === 'linebot'">
            <line
                class="stroke-default"
                x1="16"
                y1="3"
                x2="16"
                y2="6"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <circle class="fill-default anim-antenna" cx="16" cy="3" r="1.4" />
            <rect
                class="stroke-default"
                x="6"
                y="8"
                width="20"
                height="16"
                rx="4"
                stroke-width="1.5"
            />
            <path
                class="stroke-default"
                d="M11 24 L11 27 L14 24"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <circle class="fill-default anim-eye-l" cx="12" cy="16" r="1.4" />
            <circle class="fill-default anim-eye-r" cx="20" cy="16" r="1.4" />
        </template>

        <!-- wslab (websocket oscilloscope) -->
        <template v-else-if="name === 'wslab'">
            <rect
                class="stroke-default"
                x="4"
                y="6"
                width="24"
                height="18"
                rx="2"
                stroke-width="1.5"
            />
            <polyline
                class="stroke-default anim-ws-line"
                points="6,20 10,14 13,18 17,10 21,16 26,11"
                stroke-width="1.5"
                fill="none"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <line
                class="stroke-default"
                x1="4"
                y1="28"
                x2="28"
                y2="28"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.4"
            />
            <line
                class="stroke-default"
                x1="16"
                y1="24"
                x2="16"
                y2="28"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.4"
            />
        </template>

        <!-- orch (mini-orch server monitor) -->
        <template v-else-if="name === 'orch'">
            <rect
                class="stroke-default"
                x="6"
                y="4"
                width="20"
                height="24"
                rx="2"
                stroke-width="1.5"
            />
            <rect
                class="stroke-default"
                x="9"
                y="8"
                width="14"
                height="4"
                rx="1"
                stroke-width="1.2"
            />
            <rect
                class="stroke-default"
                x="9"
                y="14"
                width="14"
                height="4"
                rx="1"
                stroke-width="1.2"
            />
            <rect
                class="stroke-default"
                x="9"
                y="20"
                width="14"
                height="4"
                rx="1"
                stroke-width="1.2"
            />
            <circle class="fill-default anim-led-1" cx="20" cy="10" r="1.2" />
            <circle class="fill-default anim-led-2" cx="20" cy="16" r="1.2" />
            <circle class="fill-default anim-led-3" cx="20" cy="22" r="1.2" />
        </template>

        <!-- tour -->
        <template v-else-if="name === 'tour'">
            <ellipse
                class="stroke-default anim-pin-ring"
                cx="16"
                cy="26"
                rx="6"
                ry="2"
                stroke-width="1.5"
            />
            <g class="anim-pin">
                <path
                    class="stroke-default"
                    d="M16 4 C 11 4, 7 8, 7 13 C 7 18, 12 22, 16 26 C 20 22, 25 18, 25 13 C 25 8, 21 4, 16 4 Z"
                    stroke-width="1.5"
                    stroke-linejoin="round"
                />
                <circle class="fill-default" cx="16" cy="13" r="2.4" />
            </g>
        </template>

        <!-- story (open book) -->
        <template v-else-if="name === 'story'">
            <path
                class="stroke-default anim-book-left"
                d="M16 7 Q 10 7 5 10 L5 24 Q 10 21 16 22 Z"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <path
                class="stroke-default anim-book-right"
                d="M16 7 Q 22 7 27 10 L27 24 Q 22 21 16 22 Z"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <line
                class="stroke-default"
                x1="16"
                y1="7"
                x2="16"
                y2="22"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <line
                class="stroke-default anim-story-1"
                x1="8"
                y1="14"
                x2="14"
                y2="13.5"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.55"
            />
            <line
                class="stroke-default anim-story-2"
                x1="8"
                y1="17"
                x2="14"
                y2="16.5"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.55"
            />
            <line
                class="stroke-default anim-story-3"
                x1="8"
                y1="20"
                x2="14"
                y2="19.5"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.55"
            />
            <line
                class="stroke-default anim-story-1"
                x1="18"
                y1="13.5"
                x2="24"
                y2="14"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.55"
            />
            <line
                class="stroke-default anim-story-2"
                x1="18"
                y1="16.5"
                x2="24"
                y2="17"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.55"
            />
            <line
                class="stroke-default anim-story-3"
                x1="18"
                y1="19.5"
                x2="24"
                y2="20"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.55"
            />
        </template>

        <!-- cv (computer vision — eye + detection brackets) -->
        <template v-else-if="name === 'cv'">
            <!-- corner detection brackets -->
            <path
                class="stroke-default anim-cv-bracket"
                d="M5 11 L5 8 L8 8"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                opacity="0.5"
            />
            <path
                class="stroke-default anim-cv-bracket"
                d="M24 8 L27 8 L27 11"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                opacity="0.5"
            />
            <path
                class="stroke-default anim-cv-bracket"
                d="M5 21 L5 24 L8 24"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                opacity="0.5"
            />
            <path
                class="stroke-default anim-cv-bracket"
                d="M24 24 L27 24 L27 21"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                opacity="0.5"
            />
            <!-- eye outline -->
            <path
                class="stroke-default"
                d="M4 16 Q10 8 16 8 Q22 8 28 16 Q22 24 16 24 Q10 24 4 16 Z"
                stroke-width="1.5"
                stroke-linejoin="round"
            />
            <!-- iris -->
            <circle
                class="stroke-default anim-cv-iris"
                cx="16"
                cy="16"
                r="4.5"
                stroke-width="1.5"
            />
            <!-- pupil -->
            <circle class="fill-default" cx="16" cy="16" r="1.8" />
        </template>

        <!-- mcp (checklist) -->
        <template v-else-if="name === 'mcp'">
            <rect
                class="stroke-default"
                x="7"
                y="5"
                width="18"
                height="22"
                rx="2"
                stroke-width="1.5"
            />
            <line
                class="stroke-default"
                x1="11"
                y1="11"
                x2="21"
                y2="11"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.7"
            />
            <line
                class="stroke-default"
                x1="11"
                y1="16"
                x2="21"
                y2="16"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.7"
            />
            <line
                class="stroke-default"
                x1="11"
                y1="21"
                x2="17"
                y2="21"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.7"
            />
            <polyline
                class="stroke-default"
                points="8,11 9.5,12.5 11,10"
                stroke-width="1.3"
                stroke-linecap="round"
                stroke-linejoin="round"
                fill="none"
                opacity="0"
            />
        </template>

        <!-- gacha -->
        <template v-else-if="name === 'gacha'">
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="11"
                stroke-width="1.5"
            />
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="6"
                stroke-width="1.5"
                opacity="0.5"
            />
            <circle
                class="fill-default anim-radar-blip"
                cx="16"
                cy="10"
                r="1.6"
            />
            <circle class="fill-default" cx="16" cy="16" r="1.6" />
            <path
                class="stroke-default anim-radar-arm"
                d="M16 16 L16 5"
                stroke-width="1.5"
                stroke-linecap="round"
                opacity="0"
            />
        </template>
        <!-- memory (knowledge graph nodes) -->
        <template v-else-if="name === 'memory'">
            <circle class="fill-default" cx="16" cy="9" r="2.5" />
            <circle class="fill-default" cx="9" cy="21" r="2.5" />
            <circle class="fill-default" cx="23" cy="21" r="2.5" />
            <circle
                class="stroke-default"
                cx="16"
                cy="15"
                r="3"
                stroke-width="1.5"
                fill="none"
            />
            <line
                class="stroke-default"
                x1="16"
                y1="11.5"
                x2="16"
                y2="12"
                stroke-width="1.2"
            />
            <line
                class="stroke-default"
                x1="13.4"
                y1="17"
                x2="11"
                y2="19"
                stroke-width="1.2"
            />
            <line
                class="stroke-default"
                x1="18.6"
                y1="17"
                x2="21"
                y2="19"
                stroke-width="1.2"
            />
        </template>

        <!-- edge (edge detection — framed contour) -->
        <template v-else-if="name === 'edge'">
            <rect
                class="stroke-default"
                x="5"
                y="5"
                width="22"
                height="22"
                rx="3"
                stroke-width="1.5"
            />
            <path
                class="stroke-default anim-cv-iris"
                d="M9 22 L14 12 L18 18 L23 9"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <line
                class="stroke-default"
                x1="9"
                y1="11"
                x2="12.5"
                y2="11"
                stroke-width="1.2"
                stroke-linecap="round"
                opacity="0.45"
            />
        </template>

        <!-- gesture (open hand) -->
        <template v-else-if="name === 'gesture'">
            <line
                class="stroke-default"
                x1="11"
                y1="15"
                x2="11"
                y2="9"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <line
                class="stroke-default"
                x1="15"
                y1="14"
                x2="15"
                y2="6"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <line
                class="stroke-default"
                x1="19"
                y1="14"
                x2="19"
                y2="6.5"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <line
                class="stroke-default"
                x1="22.5"
                y1="15"
                x2="22.5"
                y2="9"
                stroke-width="1.5"
                stroke-linecap="round"
            />
            <path
                class="stroke-default"
                d="M11 15 V13 a1.7 1.7 0 0 0 -3.4 0.3 L7.7 19 a8 8 0 0 0 8 8 a8 8 0 0 0 8 -8 V14"
                stroke-width="1.5"
                stroke-linejoin="round"
                stroke-linecap="round"
                fill="none"
            />
        </template>

        <!-- ai (group — sparkle) -->
        <template v-else-if="name === 'ai'">
            <path
                class="stroke-default"
                d="M16 5 L18 13 L26 16 L18 19 L16 27 L14 19 L6 16 L14 13 Z"
                stroke-width="1.5"
                stroke-linejoin="round"
            />
            <circle
                class="fill-default anim-radar-blip"
                cx="24"
                cy="8"
                r="1.3"
            />
            <circle class="fill-default" cx="8" cy="24" r="1" opacity="0.7" />
        </template>

        <!-- ws (group — broadcast waves) -->
        <template v-else-if="name === 'ws'">
            <circle class="fill-default" cx="16" cy="23" r="2" />
            <path
                class="stroke-default"
                d="M10 18 a8.5 8.5 0 0 1 12 0"
                stroke-width="1.5"
                stroke-linecap="round"
                fill="none"
            />
            <path
                class="stroke-default"
                d="M6.5 14 a13.5 13.5 0 0 1 19 0"
                stroke-width="1.5"
                stroke-linecap="round"
                fill="none"
                opacity="0.55"
            />
        </template>

        <!-- tools (group — gear) -->
        <template v-else-if="name === 'tools'">
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="5.5"
                stroke-width="1.5"
            />
            <circle
                class="stroke-default"
                cx="16"
                cy="16"
                r="2.2"
                stroke-width="1.5"
            />
            <g
                class="stroke-default anim-gear-rot"
                stroke-width="1.5"
                stroke-linecap="round"
            >
                <line x1="16" y1="4" x2="16" y2="8" />
                <line x1="16" y1="24" x2="16" y2="28" />
                <line x1="4" y1="16" x2="8" y2="16" />
                <line x1="24" y1="16" x2="28" y2="16" />
                <line x1="7.8" y1="7.8" x2="10.5" y2="10.5" />
                <line x1="21.5" y1="21.5" x2="24.2" y2="24.2" />
                <line x1="24.2" y1="7.8" x2="21.5" y2="10.5" />
                <line x1="10.5" y1="21.5" x2="7.8" y2="24.2" />
            </g>
        </template>

        <!-- apps (group — 2x2 grid) -->
        <template v-else-if="name === 'apps'">
            <rect
                class="stroke-default"
                x="6"
                y="6"
                width="8"
                height="8"
                rx="2"
                stroke-width="1.5"
            />
            <rect
                class="stroke-default"
                x="18"
                y="6"
                width="8"
                height="8"
                rx="2"
                stroke-width="1.5"
            />
            <rect
                class="stroke-default"
                x="6"
                y="18"
                width="8"
                height="8"
                rx="2"
                stroke-width="1.5"
            />
            <rect
                class="stroke-default"
                x="18"
                y="18"
                width="8"
                height="8"
                rx="2"
                stroke-width="1.5"
            />
        </template>
    </svg>
</template>

<style scoped>
.stroke-default {
    stroke: currentColor;
}
.fill-default {
    fill: currentColor;
}
</style>
