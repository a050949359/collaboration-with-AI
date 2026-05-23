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
            <path class="stroke-default" d="M5 12 L5 24 a2 2 0 0 0 2 2 h18 a2 2 0 0 0 2 -2 V14 a2 2 0 0 0 -2 -2 H16 L13 8 H7 a2 2 0 0 0 -2 2 V12" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            <path class="stroke-default anim-folder" d="M9 16 H23" stroke-width="1.5" stroke-linecap="round"/>
        </template>

        <!-- articles -->
        <template v-else-if="name === 'articles'">
            <path class="stroke-default" d="M7 5 H21 L25 9 V25 a2 2 0 0 1 -2 2 H9 a2 2 0 0 1 -2 -2 Z" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            <path class="stroke-default" d="M21 5 V9 H25" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            <path class="stroke-default anim-art-1" d="M11 14 H21" stroke-width="1.5" stroke-linecap="round"/>
            <path class="stroke-default anim-art-2" d="M11 18 H21" stroke-width="1.5" stroke-linecap="round"/>
            <path class="stroke-default anim-art-3" d="M11 22 H17" stroke-width="1.5" stroke-linecap="round"/>
        </template>

        <!-- aviation -->
        <template v-else-if="name === 'aviation'">
            <circle class="stroke-default" cx="16" cy="16" r="2.4" stroke-width="1.5"/>
            <g class="anim-prop">
                <path class="stroke-default" d="M16 14 C 16 9, 11 6, 9 8 C 11 10, 14 13, 16 14 Z" stroke-width="1.5" stroke-linejoin="round"/>
                <path class="stroke-default" d="M18 16 C 23 16, 26 21, 24 23 C 22 21, 19 18, 18 16 Z" stroke-width="1.5" stroke-linejoin="round"/>
            </g>
            <circle class="stroke-default" cx="16" cy="16" r="11" stroke-width="1.5" opacity="0.3"/>
        </template>

        <!-- airports -->
        <template v-else-if="name === 'airports'">
            <circle class="stroke-default" cx="16" cy="16" r="11" stroke-width="1.5"/>
            <circle class="stroke-default" cx="16" cy="16" r="6" stroke-width="1.5" opacity="0.5"/>
            <circle class="fill-default" cx="16" cy="16" r="1.2"/>
            <path class="stroke-default anim-radar-arm" d="M16 16 L16 5" stroke-width="1.5" stroke-linecap="round"/>
            <circle class="fill-default anim-radar-blip" cx="22" cy="11" r="1.4"/>
        </template>

        <!-- airlines -->
        <template v-else-if="name === 'airlines'">
            <path class="stroke-default anim-trail" d="M5 22 Q 12 18 17 14" stroke-width="1.5" stroke-linecap="round" opacity="0.5"/>
            <g class="anim-plane">
                <path class="stroke-default" d="M27 6 L13 14 L18 17 L21 22 Z" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
                <path class="stroke-default" d="M18 17 L20 12" stroke-width="1.5" stroke-linecap="round"/>
            </g>
        </template>

        <!-- countries -->
        <template v-else-if="name === 'countries'">
            <circle class="stroke-default" cx="16" cy="16" r="10" stroke-width="1.5"/>
            <g class="anim-globe-rot">
                <ellipse class="stroke-default" cx="16" cy="16" rx="10" ry="3.4" stroke-width="1.5"/>
                <ellipse class="stroke-default" cx="16" cy="16" rx="3.4" ry="10" stroke-width="1.5"/>
                <line class="stroke-default" x1="16" y1="6" x2="16" y2="26" stroke-width="1.5" opacity="0.5"/>
            </g>
            <ellipse class="stroke-default anim-orbit" cx="16" cy="16" rx="13" ry="4.4" stroke-width="1" stroke-dasharray="2 2" opacity="0.45"/>
            <circle class="fill-default anim-orbit-dot" cx="16" cy="16" r="1.6"/>
        </template>

        <!-- about -->
        <template v-else-if="name === 'about'">
            <circle class="stroke-default anim-info-ring" cx="16" cy="16" r="11" stroke-width="1.5"/>
            <circle class="fill-default anim-info-dot" cx="16" cy="10" r="1.4"/>
            <path class="stroke-default" d="M16 14 V22" stroke-width="1.5" stroke-linecap="round"/>
        </template>

        <!-- linebot -->
        <template v-else-if="name === 'linebot'">
            <line class="stroke-default" x1="16" y1="3" x2="16" y2="6" stroke-width="1.5" stroke-linecap="round"/>
            <circle class="fill-default anim-antenna" cx="16" cy="3" r="1.4"/>
            <rect class="stroke-default" x="6" y="8" width="20" height="16" rx="4" stroke-width="1.5"/>
            <path class="stroke-default" d="M11 24 L11 27 L14 24" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            <circle class="fill-default anim-eye-l" cx="12" cy="16" r="1.4"/>
            <circle class="fill-default anim-eye-r" cx="20" cy="16" r="1.4"/>
        </template>

        <!-- wslab (websocket oscilloscope) -->
        <template v-else-if="name === 'wslab'">
            <rect class="stroke-default" x="4" y="6" width="24" height="18" rx="2" stroke-width="1.5"/>
            <polyline class="stroke-default anim-ws-line" points="6,20 10,14 13,18 17,10 21,16 26,11" stroke-width="1.5" fill="none" stroke-linejoin="round" stroke-linecap="round"/>
            <line class="stroke-default" x1="4" y1="28" x2="28" y2="28" stroke-width="1.2" stroke-linecap="round" opacity="0.4"/>
            <line class="stroke-default" x1="16" y1="24" x2="16" y2="28" stroke-width="1.2" stroke-linecap="round" opacity="0.4"/>
        </template>

        <!-- orch (mini-orch server monitor) -->
        <template v-else-if="name === 'orch'">
            <rect class="stroke-default" x="6" y="4" width="20" height="24" rx="2" stroke-width="1.5"/>
            <rect class="stroke-default" x="9" y="8"  width="14" height="4" rx="1" stroke-width="1.2"/>
            <rect class="stroke-default" x="9" y="14" width="14" height="4" rx="1" stroke-width="1.2"/>
            <rect class="stroke-default" x="9" y="20" width="14" height="4" rx="1" stroke-width="1.2"/>
            <circle class="fill-default anim-led-1" cx="20" cy="10" r="1.2"/>
            <circle class="fill-default anim-led-2" cx="20" cy="16" r="1.2"/>
            <circle class="fill-default anim-led-3" cx="20" cy="22" r="1.2"/>
        </template>

        <!-- tour -->
        <template v-else-if="name === 'tour'">
            <ellipse class="stroke-default anim-pin-ring" cx="16" cy="26" rx="6" ry="2" stroke-width="1.5"/>
            <g class="anim-pin">
                <path class="stroke-default" d="M16 4 C 11 4, 7 8, 7 13 C 7 18, 12 22, 16 26 C 20 22, 25 18, 25 13 C 25 8, 21 4, 16 4 Z" stroke-width="1.5" stroke-linejoin="round"/>
                <circle class="fill-default" cx="16" cy="13" r="2.4"/>
            </g>
        </template>

        <!-- lab (dropdown group icon) -->
        <template v-else-if="name === 'lab'">
            <path class="stroke-default" d="M12 4 V14 L6 24 a1 1 0 0 0 0.9 1.5 H25.1 a1 1 0 0 0 0.9 -1.5 L20 14 V4" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            <line class="stroke-default" x1="10" y1="4" x2="22" y2="4" stroke-width="1.5" stroke-linecap="round"/>
            <circle class="fill-default anim-led-1" cx="12" cy="20" r="1.4"/>
            <circle class="fill-default anim-led-2" cx="17" cy="22" r="1.4"/>
            <circle class="fill-default anim-led-3" cx="22" cy="20" r="1.4"/>
        </template>

        <!-- story (open book) -->
        <template v-else-if="name === 'story'">
            <path class="stroke-default anim-book-left"  d="M16 7 Q 10 7 5 10 L5 24 Q 10 21 16 22 Z" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            <path class="stroke-default anim-book-right" d="M16 7 Q 22 7 27 10 L27 24 Q 22 21 16 22 Z" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            <line class="stroke-default" x1="16" y1="7" x2="16" y2="22" stroke-width="1.5" stroke-linecap="round"/>
            <line class="stroke-default anim-story-1" x1="8"  y1="14"   x2="14" y2="13.5" stroke-width="1.2" stroke-linecap="round" opacity="0.55"/>
            <line class="stroke-default anim-story-2" x1="8"  y1="17"   x2="14" y2="16.5" stroke-width="1.2" stroke-linecap="round" opacity="0.55"/>
            <line class="stroke-default anim-story-3" x1="8"  y1="20"   x2="14" y2="19.5" stroke-width="1.2" stroke-linecap="round" opacity="0.55"/>
            <line class="stroke-default anim-story-1" x1="18" y1="13.5" x2="24" y2="14"   stroke-width="1.2" stroke-linecap="round" opacity="0.55"/>
            <line class="stroke-default anim-story-2" x1="18" y1="16.5" x2="24" y2="17"   stroke-width="1.2" stroke-linecap="round" opacity="0.55"/>
            <line class="stroke-default anim-story-3" x1="18" y1="19.5" x2="24" y2="20"   stroke-width="1.2" stroke-linecap="round" opacity="0.55"/>
        </template>

        <!-- cv (computer vision — eye + detection brackets) -->
        <template v-else-if="name === 'cv'">
            <!-- corner detection brackets -->
            <path class="stroke-default anim-cv-bracket" d="M5 11 L5 8 L8 8"   stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.5"/>
            <path class="stroke-default anim-cv-bracket" d="M24 8 L27 8 L27 11" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.5"/>
            <path class="stroke-default anim-cv-bracket" d="M5 21 L5 24 L8 24"  stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.5"/>
            <path class="stroke-default anim-cv-bracket" d="M24 24 L27 24 L27 21" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.5"/>
            <!-- eye outline -->
            <path class="stroke-default" d="M4 16 Q10 8 16 8 Q22 8 28 16 Q22 24 16 24 Q10 24 4 16 Z" stroke-width="1.5" stroke-linejoin="round"/>
            <!-- iris -->
            <circle class="stroke-default anim-cv-iris" cx="16" cy="16" r="4.5" stroke-width="1.5"/>
            <!-- pupil -->
            <circle class="fill-default" cx="16" cy="16" r="1.8"/>
        </template>

        <!-- gacha -->
        <template v-else-if="name === 'gacha'">
            <circle class="stroke-default" cx="16" cy="16" r="11" stroke-width="1.5"/>
            <circle class="stroke-default" cx="16" cy="16" r="6" stroke-width="1.5" opacity="0.5"/>
            <circle class="fill-default anim-radar-blip" cx="16" cy="10" r="1.6"/>
            <circle class="fill-default" cx="16" cy="16" r="1.6"/>
            <path class="stroke-default anim-radar-arm" d="M16 16 L16 5" stroke-width="1.5" stroke-linecap="round" opacity="0"/>
        </template>
    </svg>
</template>

<style scoped>
/* Theming */
.stroke-default { stroke: currentColor; }
.fill-default   { fill: currentColor; }

/* Hover-triggered animations on the parent .nav-icon */

/* Propeller spin (aviation) */
.nav-icon:hover .anim-prop {
    transform-origin: 16px 16px;
    animation: propSpin 0.6s linear infinite;
}
@keyframes propSpin {
    to { transform: rotate(360deg); }
}

/* Radar arm sweep (airports) */
.nav-icon:hover .anim-radar-arm {
    transform-origin: 16px 16px;
    animation: radarSweep 1.2s linear infinite;
}
.nav-icon:hover .anim-radar-blip {
    animation: blipFade 1.2s ease-out infinite;
}
@keyframes radarSweep {
    to { transform: rotate(360deg); }
}
@keyframes blipFade {
    0%,100% { opacity: 0; }
    40%      { opacity: 1; }
    80%      { opacity: 0; }
}

/* Globe rotation (countries) */
.nav-icon:hover .anim-globe-rot {
    transform-origin: 16px 16px;
    animation: globeRot 2s linear infinite;
}
.nav-icon:hover .anim-orbit {
    transform-origin: 16px 16px;
    animation: orbitRot 3s linear infinite;
}
.nav-icon:hover .anim-orbit-dot {
    animation: orbitDot 3s linear infinite;
    offset-path: ellipse(13px 4.4px at 16px 16px);
    offset-rotate: 0deg;
}
@keyframes globeRot {
    to { transform: rotate(360deg); }
}
@keyframes orbitRot {
    to { transform: rotate(360deg); }
}
@keyframes orbitDot {
    from { transform: rotate(0deg) translateX(13px) rotate(0deg); transform-origin: 16px 16px; }
    to   { transform: rotate(360deg) translateX(13px) rotate(-360deg); transform-origin: 16px 16px; }
}

/* Plane fly (airlines) */
.nav-icon:hover .anim-plane {
    animation: planeFly 0.8s ease-in-out infinite alternate;
}
.nav-icon:hover .anim-trail {
    animation: trailFade 0.8s ease-in-out infinite alternate;
}
@keyframes planeFly {
    from { transform: translate(0, 0); }
    to   { transform: translate(-2px, 2px); }
}
@keyframes trailFade {
    from { opacity: 0.5; }
    to   { opacity: 0.15; }
}

/* Articles lines shimmer */
.nav-icon:hover .anim-art-1 { animation: lineSlide 0.8s ease forwards; }
.nav-icon:hover .anim-art-2 { animation: lineSlide 0.8s ease 0.1s forwards; }
.nav-icon:hover .anim-art-3 { animation: lineSlide 0.8s ease 0.2s forwards; }
@keyframes lineSlide {
    from { opacity: 0.3; }
    to   { opacity: 1; }
}

/* Info ring pulse (about) */
.nav-icon:hover .anim-info-ring {
    animation: ringPulse 1s ease-in-out infinite;
}
.nav-icon:hover .anim-info-dot {
    animation: dotBounce 0.6s ease-in-out infinite alternate;
}
@keyframes ringPulse {
    0%,100% { opacity: 1; }
    50%     { opacity: 0.4; }
}
@keyframes dotBounce {
    from { transform: translateY(0); }
    to   { transform: translateY(-1.5px); }
}

/* Linebot eyes blink + antenna bounce */
.nav-icon:hover .anim-eye-l,
.nav-icon:hover .anim-eye-r {
    animation: eyeBlink 1s ease-in-out infinite;
}
.nav-icon:hover .anim-antenna {
    animation: antennaBounce 0.5s ease-in-out infinite alternate;
}
@keyframes eyeBlink {
    0%,90%,100% { opacity: 1; transform: scaleY(1); }
    95%         { opacity: 0.1; transform: scaleY(0.1); }
}
@keyframes antennaBounce {
    from { transform: translateY(0); }
    to   { transform: translateY(-2px); }
}

/* Tour pin bounce */
.nav-icon:hover .anim-pin {
    transform-origin: 16px 26px;
    animation: pinBounce 0.5s ease-in-out infinite alternate;
}
.nav-icon:hover .anim-pin-ring {
    animation: ringScale 0.5s ease-in-out infinite alternate;
    transform-origin: 16px 26px;
}
@keyframes pinBounce {
    from { transform: translateY(0); }
    to   { transform: translateY(-3px); }
}
@keyframes ringScale {
    from { transform: scaleX(1);   opacity: 1; }
    to   { transform: scaleX(1.3); opacity: 0.4; }
}

/* Orch server LEDs blink in sequence */
/* WS Lab — signal pulse */
.nav-icon:hover .anim-ws-line { animation: wsPulse 0.6s ease-in-out infinite alternate; }
@keyframes wsPulse {
    from { opacity: 0.5; stroke-width: 1.2px; }
    to   { opacity: 1;   stroke-width: 1.8px; }
}

.nav-icon:hover .anim-led-1 { animation: ledBlink 1s ease-in-out 0s    infinite; }
.nav-icon:hover .anim-led-2 { animation: ledBlink 1s ease-in-out 0.3s  infinite; }
.nav-icon:hover .anim-led-3 { animation: ledBlink 1s ease-in-out 0.6s  infinite; }
@keyframes ledBlink {
    0%,100% { opacity: 1; }
    50%     { opacity: 0.15; }
}

/* Open book — pages spread + lines shimmer */
.nav-icon:hover .anim-book-left  { animation: bookSpreadLeft  0.35s ease-out forwards; }
.nav-icon:hover .anim-book-right { animation: bookSpreadRight 0.35s ease-out forwards; }
@keyframes bookSpreadLeft  { to { transform: translateX(-1.5px); } }
@keyframes bookSpreadRight { to { transform: translateX( 1.5px); } }

.nav-icon:hover .anim-story-1 { animation: storyLine 0.5s ease 0s    forwards; }
.nav-icon:hover .anim-story-2 { animation: storyLine 0.5s ease 0.1s  forwards; }
.nav-icon:hover .anim-story-3 { animation: storyLine 0.5s ease 0.2s  forwards; }
@keyframes storyLine {
    from { opacity: 0.25; }
    to   { opacity: 1; }
}

/* CV — brackets focus in, iris pulse */
.nav-icon:hover .anim-cv-bracket {
    animation: cvBracket 0.3s ease-out forwards;
}
@keyframes cvBracket {
    to { opacity: 1; }
}
.nav-icon:hover .anim-cv-iris {
    transform-origin: 16px 16px;
    animation: cvIris 1s ease-in-out infinite;
}
@keyframes cvIris {
    0%,100% { transform: scale(1);   opacity: 1; }
    50%     { transform: scale(1.12); opacity: 0.6; }
}

/* Home / folder open */
.nav-icon:hover .anim-folder {
    animation: folderSlide 0.4s ease-out forwards;
}
@keyframes folderSlide {
    from { opacity: 0; transform: translateY(2px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
