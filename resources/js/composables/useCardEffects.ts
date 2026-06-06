import { animate } from 'animejs';
import { nextTick, onMounted, onUnmounted, watch } from 'vue';
import type { WatchSource } from 'vue';

export function useCardEffects(
    selector = '.js-tilt-card',
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

            let raf = 0;
            let tx = 0,
                ty = 0;
            let mx = 0.5,
                my = 0.5;
            let isHover = false;

            const glow = card.querySelector<HTMLElement>('.glow');

            wrap.style.perspective = '800px';

            function loop() {
                if (!isHover) {
                    return;
                }

                tx += (-(my - 0.5) * 9 - tx) * 0.1;
                ty += ((mx - 0.5) * 9 - ty) * 0.1;
                card.style.transform = `rotateX(${tx.toFixed(2)}deg) rotateY(${ty.toFixed(2)}deg)`;
                raf = requestAnimationFrame(loop);
            }

            function onEnter() {
                isHover = true;
                loop();
            }

            function onLeave() {
                isHover = false;
                cancelAnimationFrame(raf);
                animate(card, {
                    rotateX: '0deg',
                    rotateY: '0deg',
                    duration: 500,
                    ease: 'outExpo',
                });

                if (glow) {
                    glow.style.background = '';
                }
            }

            function onMove(e: MouseEvent) {
                const r = card.getBoundingClientRect();
                mx = (e.clientX - r.left) / r.width;
                my = (e.clientY - r.top) / r.height;

                if (glow) {
                    glow.style.background = `radial-gradient(circle at ${(mx * 100).toFixed(1)}% ${(my * 100).toFixed(1)}%, rgba(0,255,136,0.09) 0%, transparent 60%)`;
                }
            }

            wrap.addEventListener('mouseenter', onEnter);
            wrap.addEventListener('mouseleave', onLeave);
            wrap.addEventListener('mousemove', onMove);

            cleanups.push(() => {
                cancelAnimationFrame(raf);
                wrap.style.perspective = '';
                wrap.removeEventListener('mouseenter', onEnter);
                wrap.removeEventListener('mouseleave', onLeave);
                wrap.removeEventListener('mousemove', onMove);
                card.style.transform = '';

                if (glow) {
                    glow.style.background = '';
                }
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
