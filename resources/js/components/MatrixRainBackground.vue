<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const canvasRef = ref<HTMLCanvasElement | null>(null);
let rafId = 0;
let resizeHandler: () => void;

onMounted(() => {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d')!;

    const CHARS =
        'ｦｧｨｩｪｫｬｭｮｯｰｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉﾊﾋﾌﾍﾎﾏﾐﾑﾒﾓﾔﾕﾖﾗﾘﾙﾚﾛﾜﾝ0123456789ABCDEF'.split(
            '',
        );
    const COL_W = 14;
    const arcSize = (r: number) => 14 + 44 * r * r;

    interface Stream {
        col: number;
        size: number;
        speed: number;
        alpha: number;
        yOffset: number;
        textLen: number;
        y: number;
        history: string[];
    }

    function makeStream(col: number, cosVal: number, ratio: number): Stream {
        const textLen = 20 + Math.floor(Math.random() * 30);
        const ar = Math.abs(ratio);

        return {
            col,
            size: arcSize(ar),
            speed: 0.06 + (1 - cosVal) * 0.16,
            alpha: (0.06 + 0.94 * Math.pow(ar, 1.5)) * 0.45,
            yOffset: cosVal * -120,
            textLen,
            y: (Math.random() - 1) * 80,
            history: Array.from(
                { length: textLen },
                () => CHARS[Math.floor(Math.random() * CHARS.length)],
            ),
        };
    }

    let streams: Stream[] = [];

    function init() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        streams = [];
        const columns = Math.floor(canvas.width / COL_W);
        let i = 0;

        while (i < columns) {
            const ratio = (i / columns - 0.5) * 2;
            const cosVal = Math.cos(ratio * Math.PI * 0.42);
            const step = Math.max(
                1,
                Math.round(arcSize(Math.abs(ratio)) / COL_W),
            );

            if (Math.random() < 0.85 - 0.5 * ratio * ratio) {
                streams.push(makeStream(i, cosVal, ratio));
            }

            i += step;
        }
    }

    function loop() {
        ctx.fillStyle = 'rgba(0,0,0,0.18)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        for (const s of streams) {
            s.y += s.speed;

            if (Math.random() < 0.008) {
                s.history[Math.floor(Math.random() * s.textLen)] =
                    CHARS[Math.floor(Math.random() * CHARS.length)];
            }

            if (s.y * s.size + s.yOffset > canvas.height + 500) {
                s.y = -s.textLen;
            }

            ctx.font = `bold ${s.size}px monospace`;
            const x = s.col * COL_W;

            for (let i = 0; i < s.textLen; i++) {
                const y =
                    (s.y - i) * s.size * 0.78 + canvas.height / 2 + s.yOffset;

                if (y < 0 || y > canvas.height) {
                    continue;
                }

                const ta = s.alpha * (1 - i / s.textLen);

                if (ta <= 0) {
                    continue;
                }

                ctx.fillStyle =
                    i === 0
                        ? `rgba(200,255,220,${s.alpha})`
                        : i < 5
                          ? `rgba(107,220,159,${ta})`
                          : `rgba(40,120,80,${ta})`;
                ctx.fillText(s.history[i], x, y);
            }
        }

        rafId = requestAnimationFrame(loop);
    }

    init();
    loop();
    resizeHandler = init;
    window.addEventListener('resize', resizeHandler);
});

onUnmounted(() => {
    cancelAnimationFrame(rafId);
    window.removeEventListener('resize', resizeHandler);
});
</script>

<template>
    <canvas ref="canvasRef" class="bg-anim-rain" />
</template>
