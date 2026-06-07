import { nextTick, onMounted, onUnmounted, watch } from 'vue';
import type { WatchSource } from 'vue';

interface BrushPt {
    x: number;
    y: number;
    w: number;
    a: number;
}

function easeOutCubic(t: number) {
    return 1 - Math.pow(1 - t, 3);
}

function easeInCubic(t: number) {
    return t * t * t;
}

function buildPath(w: number, h: number): BrushPt[] {
    const pts: BrushPt[] = [];
    const pad = 2;
    const corners = [
        { x: pad, y: pad },
        { x: w - pad, y: pad },
        { x: w - pad, y: h - pad },
        { x: pad, y: h - pad },
        { x: pad, y: pad },
    ];

    for (let s = 0; s < 4; s++) {
        const a = corners[s],
            b = corners[s + 1];
        const steps = Math.ceil(Math.hypot(b.x - a.x, b.y - a.y) / 10.0);

        for (let i = 0; i < steps; i++) {
            const t = i / steps;
            pts.push({
                x: a.x + (b.x - a.x) * t + (Math.random() - 0.5) * 1.4,
                y: a.y + (b.y - a.y) * t + (Math.random() - 0.5) * 1.4,
                w:
                    0.5 +
                    Math.abs(Math.sin(i * 0.22 + s)) * 2.6 +
                    Math.random() * 0.7,
                a: 0.45 + Math.random() * 0.35,
            });
        }
    }

    return pts;
}

export function useCardEffectsInk(
    selector = '.ink-card',
    trigger?: WatchSource,
) {
    const cleanups: (() => void)[] = [];

    function setup() {
        cleanups.forEach((fn) => fn());
        cleanups.length = 0;

        document.querySelectorAll<HTMLElement>(selector).forEach((card) => {
            const wrap = card.parentElement;

            if (!wrap) {
                return;
            }

            const bc = wrap.querySelector<HTMLCanvasElement>('.border-canvas');

            if (!bc) {
                return;
            }

            const ctxOrNull = bc.getContext('2d');

            if (!ctxOrNull) {
                return;
            }

            const ctx = ctxOrNull;
            let pts: BrushPt[] = [];
            let p = 0;
            let rafId = 0;
            let logicalW = 0;
            let logicalH = 0;
            const strokeColor =
                getComputedStyle(card)
                    .getPropertyValue('--binary-primary')
                    .trim() || '#000000';

            function sizeCanvas() {
                const r = card.getBoundingClientRect();
                const dpr = window.devicePixelRatio || 1;
                logicalW = r.width + 4;
                logicalH = r.height + 4;
                bc!.width = logicalW * dpr;
                bc!.height = logicalH * dpr;
                bc!.style.width = logicalW + 'px';
                bc!.style.height = logicalH + 'px';
                bc!.style.top = '-2px';
                bc!.style.left = '-2px';
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                pts = buildPath(logicalW, logicalH);
            }

            function drawBrush(progress: number) {
                ctx.clearRect(0, 0, logicalW, logicalH);
                const end = Math.floor(progress * pts.length);

                if (end < 2) {
                    return;
                }

                ctx.strokeStyle = strokeColor;
                ctx.lineCap = 'round';

                for (let i = 1; i < end; i++) {
                    const a = pts[i - 1],
                        b = pts[i];
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(b.x, b.y);
                    ctx.globalAlpha = b.a;
                    ctx.lineWidth = b.w;
                    ctx.stroke();
                }

                ctx.globalAlpha = 1;
            }

            function runDraw() {
                cancelAnimationFrame(rafId);
                const from = p;
                let startTs: number | null = null;

                function step(ts: number) {
                    if (startTs === null) {
                        startTs = ts;
                    }

                    const t = Math.min((ts - startTs) / 850, 1);
                    p = from + (1 - from) * easeOutCubic(t);
                    drawBrush(p);

                    if (t < 1) {
                        rafId = requestAnimationFrame(step);
                    }
                }

                rafId = requestAnimationFrame(step);
            }

            function runErase() {
                cancelAnimationFrame(rafId);
                const from = p;
                let startTs: number | null = null;

                function step(ts: number) {
                    if (startTs === null) {
                        startTs = ts;
                    }

                    const t = Math.min((ts - startTs) / 450, 1);
                    p = from * (1 - easeInCubic(t));
                    drawBrush(p);

                    if (t < 1) {
                        rafId = requestAnimationFrame(step);
                    } else {
                        ctx.clearRect(0, 0, logicalW, logicalH);
                    }
                }

                rafId = requestAnimationFrame(step);
            }

            function onEnter() {
                sizeCanvas();
                runDraw();
            }

            function onLeave() {
                runErase();
            }

            wrap.addEventListener('mouseenter', onEnter);
            wrap.addEventListener('mouseleave', onLeave);

            cleanups.push(() => {
                cancelAnimationFrame(rafId);
                wrap.removeEventListener('mouseenter', onEnter);
                wrap.removeEventListener('mouseleave', onLeave);
                ctx.clearRect(0, 0, logicalW, logicalH);
            });
        });
    }

    onMounted(setup);

    if (trigger !== undefined) {
        watch(trigger, async () => {
            await nextTick();
            setup();
        });
    }

    onUnmounted(() => {
        cleanups.forEach((fn) => fn());
    });
}
