import { animate } from 'animejs';
import { nextTick, onMounted, onUnmounted, watch } from 'vue';
import type { WatchSource } from 'vue';

const SLOW = 2.8;
const SPEED = 110;
const TAIL = 300;
const SAMPLES = 80;

interface Point {
    x: number;
    y: number;
}
// line seg: from/to defined; arc seg: cx/cy/r/startAngle/sweep defined
interface Seg {
    len: number;
    from?: Point;
    to?: Point;
    cx?: number;
    cy?: number;
    r?: number;
    startAngle?: number;
    sweep?: number;
}

function buildSegs(w: number, h: number, radius: number): Seg[] {
    const r = Math.min(radius, w / 2, h / 2);
    const arc = (Math.PI / 2) * r;

    if (r <= 0) {
        return [
            { from: { x: 0, y: 0 }, to: { x: w, y: 0 }, len: w },
            { from: { x: w, y: 0 }, to: { x: w, y: h }, len: h * SLOW },
            { from: { x: w, y: h }, to: { x: 0, y: h }, len: w },
            { from: { x: 0, y: h }, to: { x: 0, y: 0 }, len: h * SLOW },
        ];
    }

    return [
        // top edge →
        { from: { x: r, y: 0 }, to: { x: w - r, y: 0 }, len: w - 2 * r },
        // top-right arc (−90° → 0°)
        {
            cx: w - r,
            cy: r,
            r,
            startAngle: -Math.PI / 2,
            sweep: Math.PI / 2,
            len: arc,
        },
        // right edge ↓ (slowed)
        {
            from: { x: w, y: r },
            to: { x: w, y: h - r },
            len: (h - 2 * r) * SLOW,
        },
        // bottom-right arc (0° → 90°)
        {
            cx: w - r,
            cy: h - r,
            r,
            startAngle: 0,
            sweep: Math.PI / 2,
            len: arc,
        },
        // bottom edge ←
        { from: { x: w - r, y: h }, to: { x: r, y: h }, len: w - 2 * r },
        // bottom-left arc (90° → 180°)
        {
            cx: r,
            cy: h - r,
            r,
            startAngle: Math.PI / 2,
            sweep: Math.PI / 2,
            len: arc,
        },
        // left edge ↑ (slowed)
        {
            from: { x: 0, y: h - r },
            to: { x: 0, y: r },
            len: (h - 2 * r) * SLOW,
        },
        // top-left arc (180° → 270°)
        { cx: r, cy: r, r, startAngle: Math.PI, sweep: Math.PI / 2, len: arc },
    ];
}

function totalLen(segs: Seg[]) {
    return segs.reduce((a, g) => a + g.len, 0);
}

function ptAt(segs: Seg[], d: number): Point {
    const tot = totalLen(segs);
    d = ((d % tot) + tot) % tot;

    for (const seg of segs) {
        if (d <= seg.len) {
            if (seg.cx !== undefined) {
                const angle = seg.startAngle! + seg.sweep! * (d / seg.len);

                return {
                    x: seg.cx + seg.r! * Math.cos(angle),
                    y: seg.cy! + seg.r! * Math.sin(angle),
                };
            }

            const t = d / seg.len;

            return {
                x: seg.from!.x + (seg.to!.x - seg.from!.x) * t,
                y: seg.from!.y + (seg.to!.y - seg.from!.y) * t,
            };
        }

        d -= seg.len;
    }

    return segs[0].from ?? { x: 0, y: 0 };
}

function drawHead(
    ctx: CanvasRenderingContext2D,
    segs: Seg[],
    d: number,
    cA: [number, number, number],
    cB: [number, number, number],
    flip: boolean,
) {
    for (let s = 0; s < SAMPLES; s++) {
        const frac = s / SAMPLES;
        const td = flip ? d + frac * TAIL : d - frac * TAIL;
        const pt = ptAt(segs, td);
        const pn = ptAt(segs, flip ? td - TAIL / SAMPLES : td + TAIL / SAMPLES);
        const alpha = (1 - frac) * 0.8;
        const r = Math.round(cA[0] + (cB[0] - cA[0]) * frac);
        const g = Math.round(cA[1] + (cB[1] - cA[1]) * frac);
        const b = Math.round(cA[2] + (cB[2] - cA[2]) * frac);
        ctx.strokeStyle = `rgba(${r},${g},${b},${alpha})`;
        ctx.lineWidth = 1.5;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(pt.x, pt.y);
        ctx.lineTo(pn.x, pn.y);
        ctx.stroke();
    }

    const head = ptAt(segs, d);
    const hg = ctx.createRadialGradient(head.x, head.y, 0, head.x, head.y, 20);
    hg.addColorStop(0, `rgba(${cA[0]},${cA[1]},${cA[2]},0.7)`);
    hg.addColorStop(0.4, `rgba(${cA[0]},${cA[1]},${cA[2]},0.2)`);
    hg.addColorStop(1, 'transparent');
    ctx.fillStyle = hg;
    ctx.beginPath();
    ctx.arc(head.x, head.y, 20, 0, Math.PI * 2);
    ctx.fill();
}

export function useCardEffectsBlob(
    selector = '.blob-card',
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

            const ctx = bc.getContext('2d')!;

            if (!ctx) {
                return;
            }

            let raf = 0;
            let isHover = false;
            let dist = 0;
            let lastTs: number | null = null;
            let segs: Seg[] = [];

            function sizeCanvas() {
                const r = card.getBoundingClientRect();
                bc!.width = r.width;
                bc!.height = r.height;
                bc!.style.width = r.width + 'px';
                bc!.style.height = r.height + 'px';
                const radius =
                    parseFloat(getComputedStyle(card).borderTopLeftRadius) || 0;
                segs = buildSegs(r.width, r.height, radius);
            }

            function draw(d: number) {
                const w = bc!.width,
                    h = bc!.height;

                if (!w || !h || !segs.length) {
                    return;
                }

                ctx.clearRect(0, 0, w, h);
                const opp = d + totalLen(segs) / 2;
                drawHead(ctx, segs, d, [220, 110, 40], [105, 12, 150], false);
                drawHead(ctx, segs, opp, [120, 20, 165], [220, 110, 40], false);
            }

            function borderLoop(ts: number) {
                if (!isHover) {
                    return;
                }

                if (lastTs !== null) {
                    dist += (SPEED * (ts - lastTs)) / 1000;
                }

                lastTs = ts;
                draw(dist);
                raf = requestAnimationFrame(borderLoop);
            }

            function onEnter() {
                isHover = true;
                sizeCanvas();
                lastTs = null;
                raf = requestAnimationFrame(borderLoop);
                animate(card, {
                    translateY: '-4px',
                    duration: 300,
                    ease: 'outExpo',
                });
            }

            function onLeave() {
                isHover = false;
                cancelAnimationFrame(raf);
                ctx.clearRect(0, 0, bc!.width, bc!.height);
                animate(card, {
                    translateY: '0px',
                    duration: 500,
                    ease: 'outExpo',
                });
            }

            wrap.addEventListener('mouseenter', onEnter);
            wrap.addEventListener('mouseleave', onLeave);

            cleanups.push(() => {
                cancelAnimationFrame(raf);
                wrap.removeEventListener('mouseenter', onEnter);
                wrap.removeEventListener('mouseleave', onLeave);
                card.style.transform = '';
                ctx.clearRect(0, 0, bc!.width, bc!.height);
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
