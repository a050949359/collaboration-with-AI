<template>
    <AppLayout>
        <div
            class="mx-auto max-w-7xl flex flex-col lg:flex-row gap-4 lg:gap-6 items-start justify-center px-4 pb-8 pt-24 lg:px-8"
        >
            <!-- Left Panel: Physics Chamber -->
            <div
                class="relative w-full lg:w-[310px] bg-[#151c17] rounded-2xl p-3 lg:p-5 border border-white/5 emerald-glow flex flex-col"
            >
                <!-- Resonance Chamber -->
                <div
                    ref="chamberEl"
                    class="relative w-full h-36 bg-[#0a100c] rounded-xl border-2 border-[#343b36] overflow-hidden mb-5 shadow-inner"
                />

                <!-- Sync Button -->
                <div class="flex flex-col items-center gap-4">
                    <button
                        :disabled="syncing || (!isHost && !canDraw) || (canDraw && drawsExhausted)"
                        class="group relative w-[72px] h-[72px] rounded-full bg-[#1c251f] border-4 border-[#343b36] flex items-center justify-center transition-all active:scale-90 hover:border-[#6bdc9f]/50 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="startSync"
                    >
                        <div
                            class="w-1 h-10 bg-[#6bdc9f] rounded-full transition-transform duration-700 ease-in-out"
                            :style="{ transform: syncing ? 'rotate(180deg)' : 'rotate(0deg)' }"
                        />
                        <div
                            class="absolute inset-0 rounded-full bg-[#6bdc9f]/5 opacity-0 group-hover:opacity-100 transition-opacity"
                        />
                    </button>
                </div>

                <!-- Extraction Port -->
                <div
                    class="mt-7 mb-4 grid grid-cols-5 gap-1.5 min-h-[48px] p-3 bg-black/30 rounded-xl border border-white/5"
                >
                    <div
                        v-for="(dot, i) in extractionDots"
                        :key="i"
                        class="w-5 h-5 rounded-full emerald-glow animate-bounce-in mx-auto border border-white/10"
                        :style="{ backgroundColor: dot.color }"
                    />
                </div>

                <div class="text-center w-full mt-auto">
                    <h2 class="text-[#6bdc9f] font-bold tracking-[0.3em] uppercase mb-2 text-[10px]">
                        {{ statusText }}
                    </h2>

                    <!-- 剩餘抽卡次數 -->
                    <div
                        v-if="drawsPerUser > 0"
                        class="mb-1.5 text-[9px] tracking-widest font-bold"
                        :class="drawsRemaining > 0 ? 'text-[#6bdc9f]/70' : 'text-red-400/70'"
                    >
                        DRAWS：{{ drawsRemaining }} / {{ drawsPerUser }}
                    </div>

                    <div class="flex gap-3 justify-center text-[9px] tracking-widest text-[#6bdc9f]/40 font-medium mb-2">
                        <span v-if="isTenPull">10-SYNC</span>
                        <span v-if="skipAnim">SKIP ANIM</span>
                        <span v-if="!canDraw" class="text-red-400/60">LOCKED</span>
                    </div>
                    <button
                        :disabled="lastResults.length === 0"
                        class="px-3 py-1.5 rounded-lg bg-[#1d2a22] border border-[#2f4739] text-[#6bdc9f] text-[9px] tracking-[0.2em] font-bold transition-colors hover:bg-[#233328] disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="showModal = true"
                    >
                        再次顯示結果
                    </button>
                </div>
            </div>

            <!-- Right Panel: 房主才看得到 -->
            <aside
                v-show="isHost"
                class="w-full lg:w-[360px] bg-[#131a15] rounded-3xl border border-white/5 p-5 lg:p-6 emerald-glow overflow-auto flex flex-col gap-6"
            >
                <!-- ── 機台控制 ── -->
                <section>
                    <div class="text-[10px] tracking-[0.35em] text-[#6bdc9f]/55 mb-2 font-bold">
                        HOST CONTROL
                    </div>
                    <h3 class="text-xl text-white font-semibold tracking-tight mb-4">
                        機台控制
                    </h3>

                    <div class="space-y-3 text-sm">
                        <!-- 開放抽卡 -->
                        <div class="flex items-center justify-between">
                            <span class="text-[#6bdc9f]/80 text-xs tracking-wider">開放加入者抽卡</span>
                            <button
                                class="w-12 h-6 rounded-full transition-colors relative"
                                :class="canDraw ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                @click="canDraw = !canDraw"
                            >
                                <span
                                    class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform"
                                    :class="canDraw ? 'translate-x-6' : ''"
                                />
                            </button>
                        </div>

                        <!-- 10 連抽 -->
                        <div class="flex items-center justify-between">
                            <span class="text-[#6bdc9f]/80 text-xs tracking-wider">10-SYNC MODE</span>
                            <button
                                class="w-12 h-6 rounded-full transition-colors relative"
                                :class="isTenPull ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                @click="isTenPull = !isTenPull"
                            >
                                <span
                                    class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform"
                                    :class="isTenPull ? 'translate-x-6' : ''"
                                />
                            </button>
                        </div>

                        <!-- 跳過動畫 -->
                        <div class="flex items-center justify-between">
                            <span class="text-[#6bdc9f]/80 text-xs tracking-wider">SKIP ANIMATION</span>
                            <button
                                class="w-12 h-6 rounded-full transition-colors relative"
                                :class="skipAnim ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                @click="skipAnim = !skipAnim"
                            >
                                <span
                                    class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform"
                                    :class="skipAnim ? 'translate-x-6' : ''"
                                />
                            </button>
                        </div>

                        <!-- 抽卡次數上限（只有開放加入者才有意義） -->
                        <div v-show="canDraw">
                            <div class="flex justify-between mb-1 text-[#6bdc9f]/80 text-xs tracking-wider">
                                <span>每人上限次數</span>
                                <span>{{ drawsPerUser === 0 ? '無限' : drawsPerUser }}</span>
                            </div>
                            <input
                                type="range"
                                :value="drawsPerUser"
                                min="0" max="20" step="1"
                                class="tune-slider w-full"
                                @input="drawsPerUser = parseInt(($event.target as HTMLInputElement).value)"
                            />
                        </div>

                        <!-- 指定品質 -->
                        <div>
                            <div class="mb-1 text-[#6bdc9f]/80 text-xs tracking-wider">指定結果品質</div>
                            <select
                                v-model="selectedQuality"
                                class="w-full rounded-lg bg-[#0f1511] border border-[#2f4739] text-[#6bdc9f] text-xs tracking-wider px-3 py-2"
                            >
                                <option v-for="tier in QUALITY_TIERS" :key="tier.name" :value="tier.name">
                                    {{ tier.name }}
                                </option>
                            </select>
                        </div>

                        <!-- 10連抽各格品質 -->
                        <div v-if="isTenPull">
                            <div class="mb-2 text-[#6bdc9f]/80 text-xs tracking-wider">10 連抽各格品質</div>
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="(_, i) in tenPullQualities"
                                    :key="i"
                                    class="block text-[10px] tracking-wider text-[#6bdc9f]/75"
                                >
                                    <div class="mb-1">第 {{ i + 1 }} 格</div>
                                    <select
                                        :value="tenPullQualities[i]"
                                        class="w-full rounded-lg px-2 py-2 text-[10px] bg-[#0f1511] border border-[#2f4739] text-[#6bdc9f]"
                                        @change="tenPullQualities[i] = ($event.target as HTMLSelectElement).value"
                                    >
                                        <option v-for="tier in QUALITY_TIERS" :key="tier.name" :value="tier.name">
                                            {{ tier.name }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 重置抽卡次數（只有開放加入者才有意義） -->
                    <button
                        v-show="canDraw"
                        class="mt-4 w-full py-2.5 rounded-xl bg-[#1d2a22] border border-[#2f4739] text-[#6bdc9f] text-xs tracking-widest font-bold hover:bg-[#233328] transition-colors"
                        @click="drawsUsed = 0"
                    >
                        重置所有人抽卡次數
                    </button>
                </section>

                <div class="border-t border-white/5" />

                <!-- ── 物理調校 ── -->
                <section v-show="false">
                    <div class="text-[10px] tracking-[0.35em] text-[#6bdc9f]/55 mb-2 font-bold">
                        PHYSICS TUNING
                    </div>
                    <h3 class="text-base text-white font-semibold tracking-tight mb-4">
                        物理參數控制
                    </h3>

                    <div class="space-y-3 text-sm">
                        <label v-for="ctrl in controlDefs" :key="ctrl.key" class="block">
                            <div class="flex justify-between mb-1 text-[#6bdc9f]/80 text-xs">
                                <span>{{ ctrl.label }}</span>
                                <span>{{ physics[ctrl.key].toFixed(ctrl.digits) }}</span>
                            </div>
                            <input
                                type="range"
                                :value="physics[ctrl.key]"
                                :min="ctrl.min"
                                :max="ctrl.max"
                                :step="ctrl.step"
                                class="tune-slider w-full"
                                @input="onSliderInput(ctrl.key, $event)"
                            />
                        </label>
                    </div>

                    <button
                        class="mt-4 w-full py-2.5 rounded-xl bg-[#1d2a22] border border-[#2f4739] text-[#6bdc9f] text-xs tracking-widest font-bold hover:bg-[#233328] transition-colors"
                        @click="resetPhysics"
                    >
                        重設為預設值
                    </button>
                </section>
            </aside>
        </div>

        <!-- Result Modal -->
        <Teleport to="body">
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
                @click.self="showModal = false"
            >
                <div class="glass-panel p-8 rounded-3xl max-w-md w-full text-center shadow-2xl">
                    <div
                        class="text-[10px] tracking-[0.4em] text-[#6bdc9f]/50 mb-2 font-bold"
                    >
                        DECODING COMPLETE
                    </div>
                    <h3 class="text-white text-2xl font-medium mb-6 tracking-tight">
                        ENTITY_DATA_RECOVERED
                    </h3>
                    <div class="grid grid-cols-2 gap-3 mb-8">
                        <div
                            v-for="(result, i) in lastResults"
                            :key="i"
                            class="bg-black/40 p-4 rounded-xl text-left"
                            :class="
                                result.quality.name === 'legendary'
                                    ? 'border border-[#d4af3755]'
                                    : 'border border-white/5'
                            "
                        >
                            <div
                                class="text-[8px] tracking-widest font-bold mb-1"
                                :class="{
                                    'gradient-text': result.quality.name === 'legendary',
                                }"
                                :style="
                                    result.quality.name !== 'legendary'
                                        ? { color: result.quality.color + 'aa' }
                                        : {}
                                "
                            >
                                {{ result.quality.code }}
                            </div>
                            <div
                                class="font-bold text-sm"
                                :class="{
                                    'gradient-text': result.quality.name === 'legendary',
                                }"
                                :style="
                                    result.quality.name !== 'legendary'
                                        ? { color: result.quality.color }
                                        : {}
                                "
                            >
                                {{ result.code }}
                            </div>
                        </div>
                    </div>
                    <button
                        class="w-full py-4 btn-gradient text-[#0f1511] font-bold rounded-xl hover:brightness-110 transition-all uppercase tracking-widest text-xs"
                        @click="showModal = false"
                    >
                        Acknowledge
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Matter from 'matter-js';
import AppLayout from '@/layouts/AppLayout.vue';

const { Engine, Render, Runner, Bodies, Composite, Events, Body } = Matter;

// ── Types ──────────────────────────────────────────────────────────────────
interface QualityTier {
    code: string;
    name: string;
    color: string;
}

interface DrawResult {
    quality: QualityTier;
    code: string;
}

type PhysicsKey =
    | 'gravity'
    | 'count'
    | 'bounce'
    | 'friction'
    | 'agitationStrength'
    | 'resonanceMs'
    | 'lockMs';

// ── Constants ──────────────────────────────────────────────────────────────
const QUALITY_TIERS: QualityTier[] = [
    { code: 'COMMON_ENTITY', name: 'common', color: '#a5d1b4' },
    { code: 'RARE_ENTITY', name: 'rare', color: '#00f2ff' },
    { code: 'EPIC_ENTITY', name: 'epic', color: '#a855f7' },
    { code: 'LEGENDARY_ENTITY', name: 'legendary', color: '#ffb3b2' },
];

const DEFAULTS: Record<PhysicsKey, number> = {
    gravity: 1.2,
    count: 25,
    bounce: 0.9,
    friction: 0.025,
    agitationStrength: 0.035,
    resonanceMs: 3000,
    lockMs: 2000,
};

const controlDefs: Array<{
    label: string;
    key: PhysicsKey;
    digits: number;
    min: number;
    max: number;
    step: number;
}> = [
    { label: '重力', key: 'gravity', digits: 2, min: 0.2, max: 2.0, step: 0.01 },
    { label: '球數', key: 'count', digits: 0, min: 1, max: 60, step: 1 },
    { label: '彈性', key: 'bounce', digits: 2, min: 0.1, max: 1.0, step: 0.01 },
    { label: '空氣阻尼', key: 'friction', digits: 3, min: 0.001, max: 0.1, step: 0.001 },
    {
        label: '震盪強度',
        key: 'agitationStrength',
        digits: 4,
        min: 0.001,
        max: 0.05,
        step: 0.0005,
    },
    { label: '共振時間 (ms)', key: 'resonanceMs', digits: 0, min: 500, max: 6000, step: 100 },
    { label: '鎖定時間 (ms)', key: 'lockMs', digits: 0, min: 200, max: 5000, step: 100 },
];

// ── Reactive State ─────────────────────────────────────────────────────────
const chamberEl = ref<HTMLDivElement>();
const physics = reactive<Record<PhysicsKey, number>>({ ...DEFAULTS });

const syncing = ref(false);
const skipAnim = ref(false);
const isTenPull = ref(false);
const selectedQuality = ref('common');
const tenPullQualities = reactive<string[]>(Array(10).fill('common'));
const extractionDots = ref<Array<{ color: string }>>([]);
const lastResults = ref<DrawResult[]>([]);
const showModal = ref(false);
const statusText = ref('System Ready');

// ── 房主狀態（暫時預設 true，之後由 channels.php presence 資料判斷）────────
const isHost = ref(true);

// ── 機台狀態（由 WebSocket MachineStateChanged 控制）─────────────────────
const canDraw = ref(true);
const drawsPerUser = ref(0);
const drawsUsed = ref(0);
const drawsRemaining = computed(() =>
    drawsPerUser.value === 0 ? Infinity : drawsPerUser.value - drawsUsed.value,
);
const drawsExhausted = computed(
    () => drawsPerUser.value > 0 && drawsUsed.value >= drawsPerUser.value,
);

// ── Matter.js Instance Vars ────────────────────────────────────────────────
let engine: Matter.Engine;
let render: Matter.Render;
let runner: Matter.Runner;
let agitationHandler: (() => void) | null = null;

// ── Physics Helpers ────────────────────────────────────────────────────────
function onSliderInput(key: PhysicsKey, event: Event) {
    const val = (event.target as HTMLInputElement).value;
    physics[key] = key === 'count' ? parseInt(val, 10) : parseFloat(val);
    applyPhysicsChange(key);
}

function applyPhysicsChange(key: PhysicsKey) {
    if (!engine) return;
    if (key === 'gravity') {
        engine.gravity.y = physics.gravity;
    } else if (key === 'bounce' || key === 'friction') {
        Composite.allBodies(engine.world).forEach((body) => {
            if (!body.isStatic) {
                body.restitution = physics.bounce;
                body.frictionAir = physics.friction;
            }
        });
    } else if (key === 'count') {
        rebuildBalls();
    }
}

function resetPhysics() {
    (Object.keys(DEFAULTS) as PhysicsKey[]).forEach((key) => {
        physics[key] = DEFAULTS[key];
        applyPhysicsChange(key);
    });
}

// ── Matter.js Setup ────────────────────────────────────────────────────────
function createBalls(count: number) {
    const el = chamberEl.value!;
    const cw = el.clientWidth;
    const ch = el.clientHeight;

    for (let i = 0; i < count; i++) {
        const ball = Bodies.circle(
            Math.random() * (cw - 20) + 10,
            Math.random() * (ch - 20) + 10,
            7,
            {
                restitution: physics.bounce,
                frictionAir: physics.friction,
                render: { fillStyle: '#6bdc9f', strokeStyle: '#ffffff22', lineWidth: 2 },
            },
        );
        Composite.add(engine.world, ball);
    }
}

function rebuildBalls() {
    const dynamic = Composite.allBodies(engine.world).filter((b) => !b.isStatic);
    if (dynamic.length) Composite.remove(engine.world, dynamic);
    createBalls(physics.count);
}

function initPhysics() {
    const el = chamberEl.value!;
    const cw = el.clientWidth;
    const ch = el.clientHeight;

    engine = Engine.create();
    engine.gravity.y = physics.gravity;

    render = Render.create({
        element: el,
        engine,
        options: { width: cw, height: ch, wireframes: false, background: 'transparent' },
    });

    const wall = { isStatic: true, render: { visible: false } };
    Composite.add(engine.world, [
        Bodies.rectangle(cw / 2, -1000, 4000, 2000, wall),
        Bodies.rectangle(cw / 2, ch + 1000, 4000, 2000, wall),
        Bodies.rectangle(-1000, ch / 2, 2000, 4000, wall),
        Bodies.rectangle(cw + 1000, ch / 2, 2000, 4000, wall),
    ]);

    createBalls(physics.count);
    Render.run(render);
    runner = Runner.create();
    Runner.run(runner, engine);
}

// ── Sync / Draw ────────────────────────────────────────────────────────────
function applyAgitation() {
    Composite.allBodies(engine.world).forEach((body) => {
        if (!body.isStatic) {
            Body.applyForce(body, body.position, {
                x: (Math.random() - 0.5) * physics.agitationStrength,
                y: (Math.random() - 0.5) * physics.agitationStrength,
            });
        }
    });
}

async function startSync() {
    if (syncing.value) return;
    syncing.value = true;
    extractionDots.value = [];

    if (skipAnim.value) {
        resolveResults();
        return;
    }

    statusText.value = 'Active Resonance...';
    agitationHandler = applyAgitation;
    Events.on(engine, 'afterUpdate', agitationHandler);
    await delay(physics.resonanceMs);
    Events.off(engine, 'afterUpdate', agitationHandler);
    agitationHandler = null;

    statusText.value = 'Vector Locked...';
    await delay(physics.lockMs);

    statusText.value = 'Ejecting...';
    resolveResults();
}

function resolveResults() {
    const count = isTenPull.value ? 10 : 1;

    // TODO(Step 2): replace with POST /api/v1/gacha/rooms/{code}/draw
    const results: DrawResult[] = Array.from({ length: count }, (_, i) => {
        const qualityName = count === 10 ? tenPullQualities[i] : selectedQuality.value;
        const tier = QUALITY_TIERS.find((q) => q.name === qualityName) ?? QUALITY_TIERS[0];
        return { quality: tier, code: `V-SYNC_${Math.floor(Math.random() * 9000) + 1000}` };
    });

    lastResults.value = results;
    extractionDots.value = results.map((r) => ({ color: r.quality.color }));

    setTimeout(() => {
        showModal.value = true;
        syncing.value = false;
        statusText.value = 'System Ready';
    }, 600);
}

function delay(ms: number) {
    return new Promise<void>((resolve) => setTimeout(resolve, ms));
}

// ── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(() => {
    initPhysics();
});

onUnmounted(() => {
    if (agitationHandler) Events.off(engine, 'afterUpdate', agitationHandler);
    if (runner) Runner.stop(runner);
    if (render) Render.stop(render);
    if (engine) Engine.clear(engine);
});
</script>

<style scoped>
.emerald-glow {
    box-shadow: 0 0 30px rgba(107, 220, 159, 0.15);
}
.btn-gradient {
    background: linear-gradient(145deg, #6bdc9f 0%, #2ca46d 100%);
}
.tune-slider {
    accent-color: #6bdc9f;
}
.glass-panel {
    background: rgba(28, 37, 31, 0.85);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(107, 220, 159, 0.2);
}
.gradient-text {
    background: linear-gradient(to bottom, #d4af37 0%, #b84a2a 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: bold;
}
@keyframes bounce-in {
    0% {
        transform: scale(0.3);
        opacity: 0;
    }
    50% {
        transform: scale(1.05);
        opacity: 0.8;
    }
    70% {
        transform: scale(0.9);
        opacity: 0.9;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}
.animate-bounce-in {
    animation: bounce-in 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
}
</style>
