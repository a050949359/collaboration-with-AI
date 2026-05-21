import Matter from 'matter-js'
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

export type PhysicsKey = 'gravity' | 'count' | 'bounce' | 'friction' | 'agitationStrength' | 'resonanceMs' | 'lockMs'

export const PHYSICS_DEFAULTS: Record<PhysicsKey, number> = {
  gravity: 1.2,
  count: 25,
  bounce: 0.9,
  friction: 0.025,
  agitationStrength: 0.035,
  resonanceMs: 3000,
  lockMs: 2000,
}

export function useGachaPhysics() {
  const { t } = useI18n()
  const chamberEl = ref<HTMLDivElement>()
  const physics = reactive<Record<PhysicsKey, number>>({ ...PHYSICS_DEFAULTS })

  let engine: Matter.Engine
  let render: Matter.Render
  let runner: Matter.Runner
  let agitationHandler: (() => void) | undefined

  function applyAgitation() {
    Matter.Composite.allBodies(engine.world).forEach((body) => {
      if (!body.isStatic) {
        Matter.Body.applyForce(body, body.position, {
          x: (Math.random() - 0.5) * physics.agitationStrength,
          y: (Math.random() - 0.5) * physics.agitationStrength,
        })
      }
    })
  }

  function initPhysics() {
    if (!chamberEl.value) return
    const cw = chamberEl.value.clientWidth
    const ch = chamberEl.value.clientHeight

    engine = Matter.Engine.create()
    engine.gravity.y = physics.gravity
    render = Matter.Render.create({
      element: chamberEl.value,
      engine,
      options: { width: cw, height: ch, wireframes: false, background: 'transparent' },
    })
    runner = Matter.Runner.create()

    const wall = { isStatic: true, render: { visible: false } }
    Matter.Composite.add(engine.world, [
      Matter.Bodies.rectangle(cw / 2, -1000, 4000, 2000, wall),
      Matter.Bodies.rectangle(cw / 2, ch + 1000, 4000, 2000, wall),
      Matter.Bodies.rectangle(-1000, ch / 2, 2000, 4000, wall),
      Matter.Bodies.rectangle(cw + 1000, ch / 2, 2000, 4000, wall),
    ])
    for (let i = 0; i < physics.count; i++) {
      Matter.Composite.add(engine.world, Matter.Bodies.circle(
        Math.random() * (cw - 20) + 10, Math.random() * (ch - 20) + 10, 7,
        { restitution: physics.bounce, frictionAir: physics.friction,
          render: { fillStyle: '#6bdc9f', strokeStyle: '#ffffff22', lineWidth: 2 } },
      ))
    }
    Matter.Render.run(render)
    Matter.Runner.run(runner, engine)
  }

  async function runAnimation(setStatus: (s: string) => void): Promise<void> {
    setStatus(t('gacha.active_resonance'))

    agitationHandler = applyAgitation
    Matter.Events.on(engine, 'afterUpdate', agitationHandler)

    await new Promise<void>((resolve) => setTimeout(resolve, physics.resonanceMs))

    Matter.Events.off(engine, 'afterUpdate', agitationHandler)
    agitationHandler = undefined

    setStatus(t('gacha.vector_locked'))

    await new Promise<void>((resolve) => setTimeout(resolve, physics.lockMs))

    setStatus(t('gacha.ejecting'))
  }

  function cleanup() {
    if (agitationHandler) {
      Matter.Events.off(engine, 'afterUpdate', agitationHandler)
      agitationHandler = undefined
    }
    if (runner) Matter.Runner.stop(runner)
    if (render) Matter.Render.stop(render)
    if (engine) Matter.Engine.clear(engine)
  }

  onMounted(() => initPhysics())
  onUnmounted(() => cleanup())

  return { chamberEl, physics, runAnimation }
}
