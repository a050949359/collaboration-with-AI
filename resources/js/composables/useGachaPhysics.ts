import Matter from 'matter-js';
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

export type PhysicsKey =
    | 'gravity'
    | 'bounce'
    | 'friction'
    | 'agitationStrength'
    | 'resonanceMs'
    | 'lockMs';

export const PHYSICS_DEFAULTS: Record<PhysicsKey, number> = {
    gravity: 1.2,
    bounce: 0.9,
    friction: 0.025,
    agitationStrength: 0.035,
    resonanceMs: 3000,
    lockMs: 2000,
};

export function useGachaPhysics() {
    const { t } = useI18n();
    const chamberEl = ref<HTMLDivElement>();
    const physics = reactive<Record<PhysicsKey, number>>({
        ...PHYSICS_DEFAULTS,
    });

    let engine: Matter.Engine;
    let render: Matter.Render;
    let runner: Matter.Runner;
    let agitationHandler: (() => void) | undefined;
    let resizeObserver: ResizeObserver | undefined;

    function applyAgitation() {
        Matter.Composite.allBodies(engine.world).forEach((body) => {
            if (!body.isStatic) {
                Matter.Body.applyForce(body, body.position, {
                    x: (Math.random() - 0.5) * physics.agitationStrength,
                    y: (Math.random() - 0.5) * physics.agitationStrength,
                });
            }
        });
    }

    function initPhysics() {
        if (!chamberEl.value) {
            return;
        }

        const cw = chamberEl.value.clientWidth;
        const ch = chamberEl.value.clientHeight;

        // 版面尚未結算（量到 0 寬高，例如隱藏 tab / display:none）時，
        // 用 ResizeObserver 等到真正可見且尺寸 > 0 再建立，避免 rAF 每幀空轉佔滿 CPU。
        // 一旦量到有效尺寸即 disconnect 收掉，不做後續 resize / 旋轉處理。
        if (cw === 0 || ch === 0) {
            if (!resizeObserver) {
                resizeObserver = new ResizeObserver(() => {
                    const w = chamberEl.value?.clientWidth ?? 0;
                    const h = chamberEl.value?.clientHeight ?? 0;

                    if (w > 0 && h > 0) {
                        resizeObserver?.disconnect();
                        resizeObserver = undefined;
                        initPhysics();
                    }
                });
                resizeObserver.observe(chamberEl.value);
            }

            return;
        }

        engine = Matter.Engine.create();
        engine.gravity.y = physics.gravity;
        render = Matter.Render.create({
            element: chamberEl.value,
            engine,
            options: {
                width: cw,
                height: ch,
                pixelRatio: window.devicePixelRatio || 1,
                wireframes: false,
                background: 'transparent',
            },
        });
        runner = Matter.Runner.create();

        const wall = { isStatic: true, render: { visible: false } };
        Matter.Composite.add(engine.world, [
            Matter.Bodies.rectangle(cw / 2, -1000, 4000, 2000, wall),
            Matter.Bodies.rectangle(cw / 2, ch + 1000, 4000, 2000, wall),
            Matter.Bodies.rectangle(-1000, ch / 2, 2000, 4000, wall),
            Matter.Bodies.rectangle(cw + 1000, ch / 2, 2000, 4000, wall),
        ]);

        const ballMix: { color: string; count: number }[] = [
            { color: '#b0b8c1', count: 15 }, // silver
            { color: '#60a5fa', count: 6 }, // azure
            { color: '#c084fc', count: 3 }, // violet
            { color: '#fbbf24', count: 1 }, // gold
        ];

        for (const slot of ballMix) {
            for (let i = 0; i < slot.count; i++) {
                Matter.Composite.add(
                    engine.world,
                    Matter.Bodies.circle(
                        Math.random() * (cw - 20) + 10,
                        Math.random() * (ch - 20) + 10,
                        7,
                        {
                            restitution: physics.bounce,
                            frictionAir: physics.friction,
                            render: {
                                fillStyle: slot.color,
                                strokeStyle: '#ffffff18',
                                lineWidth: 1,
                            },
                        },
                    ),
                );
            }
        }

        Matter.Render.run(render);
        Matter.Runner.run(runner, engine);
    }

    async function runAnimation(setStatus: (s: string) => void): Promise<void> {
        setStatus(t('gacha.active_resonance'));

        agitationHandler = applyAgitation;
        Matter.Events.on(engine, 'afterUpdate', agitationHandler);

        await new Promise<void>((resolve) =>
            setTimeout(resolve, physics.resonanceMs),
        );

        Matter.Events.off(engine, 'afterUpdate', agitationHandler);
        agitationHandler = undefined;

        setStatus(t('gacha.vector_locked'));

        await new Promise<void>((resolve) =>
            setTimeout(resolve, physics.lockMs),
        );

        setStatus(t('gacha.ejecting'));
    }

    function cleanup() {
        if (resizeObserver) {
            resizeObserver.disconnect();
            resizeObserver = undefined;
        }

        if (agitationHandler) {
            Matter.Events.off(engine, 'afterUpdate', agitationHandler);
            agitationHandler = undefined;
        }

        if (runner) {
            Matter.Runner.stop(runner);
        }

        if (render) {
            Matter.Render.stop(render);
        }

        if (engine) {
            Matter.Engine.clear(engine);
        }
    }

    onMounted(() => initPhysics());
    onUnmounted(() => cleanup());

    return { chamberEl, physics, runAnimation };
}
