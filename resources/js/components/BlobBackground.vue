<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const canvasRef = ref<HTMLCanvasElement | null>(null);
let rafId = 0;
let resizeHandler: () => void;
let visibilityHandler: () => void;

interface Blob {
    x: number;
    y: number;
    r: number;
    vx: number;
    vy: number;
    baseR: number;
    phase: number;
    phaseSpeed: number;
    color: [number, number, number];
    alpha: number;
}

const blobs: Blob[] = [];

const BLOB_DEFS: Array<{
    xRatio: number;
    yRatio: number;
    rRatio: number;
    color: [number, number, number];
    alpha: number;
    phaseOffset: number;
}> = [
    {
        xRatio: 0.25,
        yRatio: 0.4,
        rRatio: 0.38,
        color: [210, 75, 15],
        alpha: 0.55,
        phaseOffset: 0.0,
    },
    {
        xRatio: 0.72,
        yRatio: 0.55,
        rRatio: 0.32,
        color: [110, 15, 155],
        alpha: 0.5,
        phaseOffset: 1.1,
    },
    {
        xRatio: 0.55,
        yRatio: 0.18,
        rRatio: 0.22,
        color: [240, 140, 25],
        alpha: 0.3,
        phaseOffset: 2.3,
    },
    {
        xRatio: 0.15,
        yRatio: 0.78,
        rRatio: 0.26,
        color: [75, 10, 115],
        alpha: 0.38,
        phaseOffset: 3.5,
    },
    {
        xRatio: 0.82,
        yRatio: 0.22,
        rRatio: 0.2,
        color: [185, 55, 10],
        alpha: 0.28,
        phaseOffset: 4.7,
    },
    {
        xRatio: 0.5,
        yRatio: 0.82,
        rRatio: 0.24,
        color: [90, 12, 140],
        alpha: 0.32,
        phaseOffset: 5.9,
    },
];

function initBlobs(W: number, H: number) {
    blobs.length = 0;
    const minDim = Math.min(W, H);
    BLOB_DEFS.forEach((def) => {
        blobs.push({
            x: def.xRatio * W,
            y: def.yRatio * H,
            r: def.rRatio * minDim,
            baseR: def.rRatio * minDim,
            vx: (Math.random() - 0.5) * 0.18,
            vy: (Math.random() - 0.5) * 0.14,
            phase: def.phaseOffset,
            phaseSpeed: 0.0004 + Math.random() * 0.0003,
            color: def.color,
            alpha: def.alpha,
        });
    });
}

onMounted(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    let W = 0,
        H = 0;

    function resize() {
        if (!canvas || !ctx) {
            return;
        }

        W = window.innerWidth;
        H = window.innerHeight;
        canvas.width = Math.ceil(W / 4);
        canvas.height = Math.ceil(H / 4);
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';
        ctx.scale(1 / 4, 1 / 4);
        initBlobs(W, H);
    }
    resize();

    function drawBlob(blob: Blob) {
        const [r, g, b] = blob.color;
        const grad = ctx!.createRadialGradient(
            blob.x,
            blob.y,
            0,
            blob.x,
            blob.y,
            blob.r,
        );
        grad.addColorStop(0, `rgba(${r},${g},${b},${blob.alpha})`);
        grad.addColorStop(
            0.45,
            `rgba(${r},${g},${b},${(blob.alpha * 0.5).toFixed(3)})`,
        );
        grad.addColorStop(1, `rgba(${r},${g},${b},0)`);

        ctx!.beginPath();
        ctx!.arc(blob.x, blob.y, blob.r, 0, Math.PI * 2);
        ctx!.fillStyle = grad;
        ctx!.fill();
    }

    function tick() {
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas!.width, canvas!.height);
        ctx.scale(1 / 4, 1 / 4);

        ctx.fillStyle = '#07090f';
        ctx.fillRect(0, 0, W, H);

        ctx.globalCompositeOperation = 'screen';

        blobs.forEach((blob) => {
            blob.phase += blob.phaseSpeed;

            const wobble = Math.sin(blob.phase) * 0.08;
            blob.r = blob.baseR * (1 + wobble);

            blob.x += blob.vx + Math.sin(blob.phase * 0.7) * 0.12;
            blob.y += blob.vy + Math.cos(blob.phase * 0.5) * 0.1;

            const margin = blob.baseR * 0.5;

            if (blob.x < -margin) {
                blob.x = W + margin;
            }

            if (blob.x > W + margin) {
                blob.x = -margin;
            }

            if (blob.y < -margin) {
                blob.y = H + margin;
            }

            if (blob.y > H + margin) {
                blob.y = -margin;
            }

            drawBlob(blob);
        });

        ctx.globalCompositeOperation = 'source-over';

        const vignette = ctx.createRadialGradient(
            W / 2,
            H / 2,
            0,
            W / 2,
            H / 2,
            Math.max(W, H) * 0.75,
        );
        vignette.addColorStop(0, 'rgba(0,0,0,0)');
        vignette.addColorStop(0.6, 'rgba(0,0,0,0)');
        vignette.addColorStop(1, 'rgba(0,0,0,0.65)');
        ctx.fillStyle = vignette;
        ctx.fillRect(0, 0, W, H);

        rafId = requestAnimationFrame(tick);
    }

    tick();

    resizeHandler = () => {
        resize();
    };
    visibilityHandler = () => {
        if (document.hidden) {
            cancelAnimationFrame(rafId);
        } else {
            tick();
        }
    };

    window.addEventListener('resize', resizeHandler);
    document.addEventListener('visibilitychange', visibilityHandler);
});

onUnmounted(() => {
    cancelAnimationFrame(rafId);
    window.removeEventListener('resize', resizeHandler);
    document.removeEventListener('visibilitychange', visibilityHandler);
});
</script>

<template>
    <canvas ref="canvasRef" class="bg-anim-smoke" />
</template>

<style scoped>
.bg-anim-smoke {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 0;
    image-rendering: auto;
}
</style>
