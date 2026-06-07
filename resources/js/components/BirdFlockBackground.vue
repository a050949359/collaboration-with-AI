<template>
    <div class="bg-anim-ink" aria-hidden="true">
        <img src="/images/ink-zen-bg.png" class="bg-image" alt="" />
        <div class="bg-veil"></div>
        <svg
            viewBox="0 0 1000 600"
            xmlns="http://www.w3.org/2000/svg"
            class="birds-svg"
        >
            <defs>
                <filter
                    id="ink-zen-brush"
                    x="-30%"
                    y="-30%"
                    width="160%"
                    height="160%"
                >
                    <feTurbulence
                        type="fractalNoise"
                        baseFrequency="0.04, 0.65"
                        numOctaves="2"
                        result="scratchNoise"
                    />
                    <feDisplacementMap
                        in="SourceGraphic"
                        in2="scratchNoise"
                        scale="4.5"
                        xChannelSelector="R"
                        yChannelSelector="G"
                        result="displaced"
                    />
                    <feGaussianBlur
                        in="displaced"
                        stdDeviation="0.4"
                        result="final"
                    />
                    <feMerge><feMergeNode in="final" /></feMerge>
                </filter>
            </defs>

            <g
                filter="url(#ink-zen-brush)"
                fill="none"
                stroke="#0a0a0a"
                stroke-linecap="round"
            >
                <!-- 鳥 1 主角 -->
                <g
                    transform="translate(420,280) scale(-1,1) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.88"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: 0s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: 0s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: 0s"
                    />
                </g>

                <!-- 鳥 2 -->
                <g
                    transform="translate(580,220) scale(-0.75,0.75) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.70"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.2s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.2s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.2s"
                    />
                </g>

                <!-- 鳥 3 -->
                <g
                    transform="translate(660,190) scale(-0.55,0.55) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.55"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.5s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.5s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.5s"
                    />
                </g>

                <!-- 鳥 4 -->
                <g
                    transform="translate(730,165) scale(-0.40,0.40) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.42"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.8s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.8s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.8s"
                    />
                </g>

                <!-- 鳥 5 -->
                <g
                    transform="translate(800,148) scale(-0.30,0.30) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.30"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -1s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -1s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -1s"
                    />
                </g>

                <!-- 鳥 6 -->
                <g
                    transform="translate(320,300) scale(-0.80,0.80) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.65"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.3s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.3s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.3s"
                    />
                </g>

                <!-- 鳥 7 -->
                <g
                    transform="translate(240,260) scale(-0.60,0.60) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.50"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.6s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.6s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.6s"
                    />
                </g>

                <!-- 鳥 8 -->
                <g
                    transform="translate(170,235) scale(-0.42,0.42) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.35"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.9s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.9s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.9s"
                    />
                </g>

                <!-- 鳥 9 -->
                <g
                    transform="translate(500,340) scale(-0.90,0.90) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.75"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.15s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.15s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.15s"
                    />
                </g>

                <!-- 鳥 10 -->
                <g
                    transform="translate(620,310) scale(-0.65,0.65) rotate(-12)"
                    stroke-width="2.2"
                    opacity="0.52"
                >
                    <path
                        class="wL"
                        d="M -24 -18 C -10 -20, -3 -10, 0 0"
                        style="animation-delay: -0.7s"
                    />
                    <path
                        class="wR"
                        d="M 0 0 C  5 -17, 14 -17, 27 -14"
                        style="animation-delay: -0.7s"
                    />
                    <path
                        class="wT"
                        d="M -0.5 0 Q  0.5  2,  1.2  5"
                        stroke-width="1.5"
                        style="animation-delay: -0.7s"
                    />
                </g>
            </g>
        </svg>
    </div>
</template>

<style scoped>
.bg-anim-ink {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
}

.bg-image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center bottom;
    opacity: 0.35;
}

.bg-veil {
    position: absolute;
    inset: 0;
    background: rgba(249, 250, 244, 0.55);
}

.birds-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

@keyframes wingL {
    0% {
        d: path('M -24 -18 C -10 -20, -3 -10, 0 0');
    }
    35% {
        d: path('M -24  2  C -13  -2, -3  -5, 0 0');
    }
    60% {
        d: path('M -24  4  C -12   0, -3  -4, 0 0');
    }
    100% {
        d: path('M -24 -18 C -10 -20, -3 -10, 0 0');
    }
}

@keyframes wingR {
    0% {
        d: path('M 0 0 C  5 -17, 14 -17, 27 -14');
    }
    35% {
        d: path('M 0 0 C  3  -4, 12  -6, 27   1');
    }
    60% {
        d: path('M 0 0 C  2  -2, 11  -4, 27   3');
    }
    100% {
        d: path('M 0 0 C  5 -17, 14 -17, 27 -14');
    }
}

@keyframes wingTail {
    0% {
        d: path('M -0.5 0 Q  0.5  2,  1.2  5');
    }
    35% {
        d: path('M -0.5 0 Q -0.8  3, -0.3  6.5');
    }
    60% {
        d: path('M -0.5 0 Q -1.0  3.2, -0.5 7');
    }
    100% {
        d: path('M -0.5 0 Q  0.5  2,  1.2  5');
    }
}

.wL {
    animation: wingL 1.6s cubic-bezier(0.37, 0, 0.63, 1) infinite;
}

.wR {
    animation: wingR 1.6s cubic-bezier(0.37, 0, 0.63, 1) infinite;
}

.wT {
    animation: wingTail 1.6s cubic-bezier(0.37, 0, 0.63, 1) infinite;
}

@media (max-width: 768px), (prefers-reduced-motion: reduce) {
    .birds-svg g {
        filter: none !important;
    }
}
</style>
