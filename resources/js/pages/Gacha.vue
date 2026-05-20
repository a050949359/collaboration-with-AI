<template>
    <AppLayout>
        <div
            class="mx-auto max-w-7xl flex flex-col lg:flex-row gap-4 lg:gap-6 items-start justify-center px-4 pb-8 pt-24 lg:px-8"
        >
            <!-- Right Panel: Physics Chamber -->
            <div
                class="relative w-full lg:flex-[4] lg:order-last bg-[#151c17] rounded-2xl p-3 lg:p-5 border border-white/5 emerald-glow flex flex-col"
            >
                <!-- Resonance Chamber -->
                <div
                    ref="chamberEl"
                    class="relative w-full h-36 bg-[#0a100c] rounded-xl border-2 border-[#343b36] overflow-hidden mb-5 shadow-inner"
                />

                <!-- Sync Button -->
                <div class="flex flex-col items-center gap-4">
                    <button
                        :disabled="!canPressButton"
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
                        <span v-if="mode === 'in-room' && !canDraw && !isHost" class="text-red-400/60">LOCKED</span>
                    </div>

                    <button
                        :disabled="lastResults.length === 0"
                        class="px-3 py-1.5 rounded-lg bg-[#1d2a22] border border-[#2f4739] text-[#6bdc9f] text-[9px] tracking-[0.2em] font-bold transition-colors hover:bg-[#233328] disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="showModal = true"
                    >
                        再次顯示結果
                    </button>
                </div>

                <!-- In-room status bar -->
                <div v-if="mode === 'in-room' && currentRoom" class="mt-4 pt-4 border-t border-white/5">
                    <div class="flex items-center justify-between text-[10px] font-mono">
                        <div>
                            <span class="text-[#6bdc9f]/50 tracking-widest">ROOM </span>
                            <span class="text-[#6bdc9f] font-bold">{{ currentRoom.code }}</span>
                            <span v-if="isHost" class="ml-2 text-[#6bdc9f]/50">[HOST]</span>
                        </div>
                        <span
                            class="px-2 py-px rounded text-[9px] tracking-widest"
                            :class="wsStatus === 'connected' ? 'text-[#6bdc9f]/70' : 'text-[#6bdc9f]/30 animate-pulse'"
                        >{{ wsStatus }}</span>
                    </div>
                    <button
                        class="mt-2 w-full py-1.5 rounded-lg border border-red-900/50 text-red-400/70 text-[9px] tracking-widest font-bold hover:border-red-400/50 hover:text-red-400 transition-colors"
                        @click="leaveRoom"
                    >
                        {{ isHost ? '關閉房間' : '離開房間' }}
                    </button>
                </div>
            </div>

            <!-- Right Panel -->
            <aside class="w-full lg:flex-[6] min-w-0 bg-[#131a15] rounded-3xl border border-white/5 p-5 lg:p-6 emerald-glow overflow-auto flex flex-col gap-6">

                <!-- LOBBY -->
                <template v-if="mode === 'lobby'">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-[10px] tracking-[0.35em] text-[#6bdc9f]/55 mb-1 font-bold">GACHA ROOMS</div>
                            <h3 class="text-xl text-white font-semibold tracking-tight">抽卡機台列表</h3>
                        </div>
                        <button
                            v-if="user"
                            class="px-4 py-2 rounded-xl bg-[#1d2a22] border border-[#2f4739] text-[#6bdc9f] text-xs tracking-widest font-bold hover:bg-[#233328] transition-colors"
                            @click="createRoom"
                        >
                            + 建立房間
                        </button>
                    </div>

                    <div v-if="roomListLoading" class="text-[#6bdc9f]/30 text-xs tracking-widest text-center py-8">載入中…</div>
                    <div v-else-if="roomList.length === 0" class="text-[#6bdc9f]/30 text-xs tracking-widest text-center py-8">目前沒有開放的房間</div>
                    <div v-else class="flex flex-col gap-3">
                        <div
                            v-for="room in roomList"
                            :key="room.id"
                            class="flex items-center gap-4 p-4 rounded-2xl bg-black/30 border border-white/5 hover:border-[#6bdc9f]/20 transition-colors"
                        >
                            <div class="flex-1 min-w-0">
                                <div class="text-white font-semibold text-sm truncate">{{ room.room_name }}</div>
                                <div class="text-[10px] tracking-widest text-[#6bdc9f]/40 mt-0.5 flex gap-3">
                                    <span>{{ room.code }}</span>
                                    <span>{{ room.players_count }}/{{ room.max_players }}</span>
                                    <span v-if="!room.can_draw" class="text-red-400/60">LOCKED</span>
                                </div>
                            </div>
                            <button
                                class="px-4 py-1.5 rounded-xl border border-[#6bdc9f]/30 text-[#6bdc9f] text-xs tracking-widest font-bold hover:bg-[#6bdc9f]/10 transition-colors shrink-0"
                                @click="openJoinModal(room)"
                            >
                                加入
                            </button>
                        </div>
                    </div>

                    <button
                        class="mt-auto text-[10px] tracking-widest text-[#6bdc9f]/30 hover:text-[#6bdc9f]/60 transition-colors"
                        :disabled="roomListLoading"
                        @click="fetchRooms"
                    >
                        重新整理
                    </button>
                </template>

                <!-- IN-ROOM: HOST CONTROLS + DRAW HISTORY -->
                <template v-else-if="mode === 'in-room'">
                    <!-- Machine controls (host only) -->
                    <section v-if="isHost">
                        <div class="text-[10px] tracking-[0.35em] text-[#6bdc9f]/55 mb-2 font-bold">HOST CONTROL</div>
                        <h3 class="text-xl text-white font-semibold tracking-tight mb-4">機台控制</h3>

                        <div class="space-y-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-[#6bdc9f]/80 text-xs tracking-wider">開放加入者抽卡</span>
                                <button
                                    class="w-12 h-6 rounded-full transition-colors relative"
                                    :class="canDraw ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                    @click="canDraw = !canDraw; sendMachineState()"
                                >
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform" :class="canDraw ? 'translate-x-6' : ''" />
                                </button>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-[#6bdc9f]/80 text-xs tracking-wider">10-SYNC MODE</span>
                                <button
                                    class="w-12 h-6 rounded-full transition-colors relative"
                                    :class="isTenPull ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                    @click="isTenPull = !isTenPull; sendMachineState()"
                                >
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform" :class="isTenPull ? 'translate-x-6' : ''" />
                                </button>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-[#6bdc9f]/80 text-xs tracking-wider">SKIP ANIMATION</span>
                                <button
                                    class="w-12 h-6 rounded-full transition-colors relative"
                                    :class="skipAnim ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                    @click="skipAnim = !skipAnim; sendMachineState()"
                                >
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform" :class="skipAnim ? 'translate-x-6' : ''" />
                                </button>
                            </div>

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
                                    @change="drawsPerUser = parseInt(($event.target as HTMLInputElement).value); sendMachineState()"
                                />
                            </div>
                        </div>

                        <button
                            v-show="canDraw"
                            class="mt-4 w-full py-2.5 rounded-xl bg-[#1d2a22] border border-[#2f4739] text-[#6bdc9f] text-xs tracking-widest font-bold hover:bg-[#233328] transition-colors"
                            @click="drawsUsed = 0"
                        >
                            重置所有人抽卡次數
                        </button>
                    </section>

                    <div v-if="isHost" class="border-t border-white/5" />

                    <!-- Broadcast log -->
                    <section>
                        <div class="text-[10px] tracking-[0.35em] text-[#6bdc9f]/55 mb-2 font-bold">BROADCAST</div>
                        <div v-if="broadcastLog.length === 0" class="text-[#6bdc9f]/30 text-xs tracking-widest py-2 text-center">—</div>
                        <div class="flex flex-col gap-1 max-h-32 overflow-y-auto">
                            <div
                                v-for="(entry, i) in [...broadcastLog].reverse()"
                                :key="i"
                                class="flex items-baseline gap-2 text-[10px] font-mono"
                            >
                                <span class="text-[#6bdc9f]/25 shrink-0">{{ new Date(entry.ts).toLocaleTimeString() }}</span>
                                <span class="text-[#6bdc9f]/70">{{ entry.text }}</span>
                            </div>
                        </div>
                    </section>

                    <div class="border-t border-white/5" />

                    <!-- Draw history -->
                    <section>
                        <div class="text-[10px] tracking-[0.35em] text-[#6bdc9f]/55 mb-2 font-bold">DRAW LOG</div>
                        <div v-if="drawHistory.length === 0" class="text-[#6bdc9f]/30 text-xs tracking-widest py-4 text-center">等待抽卡…</div>
                        <div class="flex flex-col gap-2 max-h-64 overflow-y-auto">
                            <div
                                v-for="(event, i) in [...drawHistory].reverse()"
                                :key="i"
                                class="text-xs p-3 rounded-xl bg-black/30 border border-white/5"
                            >
                                <div class="flex justify-between mb-1.5 text-[10px]">
                                    <span class="text-[#6bdc9f]/70 font-bold tracking-wider">{{ event.player }}</span>
                                    <span class="text-[#6bdc9f]/30">{{ new Date(event.ts).toLocaleTimeString() }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="(r, j) in event.results"
                                        :key="j"
                                        class="px-1.5 py-0.5 rounded text-[9px] font-bold border"
                                        :style="{ color: r.quality.color, borderColor: r.quality.color + '55' }"
                                    >{{ r.quality.code.split('_')[0] }}</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </template>

                <!-- STANDALONE -->
                <template v-else>
                    <section>
                        <div class="text-[10px] tracking-[0.35em] text-[#6bdc9f]/55 mb-2 font-bold">HOST CONTROL</div>
                        <h3 class="text-xl text-white font-semibold tracking-tight mb-4">機台控制</h3>

                        <div class="space-y-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-[#6bdc9f]/80 text-xs tracking-wider">10-SYNC MODE</span>
                                <button
                                    class="w-12 h-6 rounded-full transition-colors relative"
                                    :class="isTenPull ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                    @click="isTenPull = !isTenPull"
                                >
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform" :class="isTenPull ? 'translate-x-6' : ''" />
                                </button>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-[#6bdc9f]/80 text-xs tracking-wider">SKIP ANIMATION</span>
                                <button
                                    class="w-12 h-6 rounded-full transition-colors relative"
                                    :class="skipAnim ? 'bg-[#6bdc9f]' : 'bg-[#2f4739]'"
                                    @click="skipAnim = !skipAnim"
                                >
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform" :class="skipAnim ? 'translate-x-6' : ''" />
                                </button>
                            </div>

                            <div>
                                <div class="mb-1 text-[#6bdc9f]/80 text-xs tracking-wider">指定結果品質</div>
                                <select
                                    v-model="selectedQuality"
                                    class="w-full rounded-lg bg-[#0f1511] border border-[#2f4739] text-[#6bdc9f] text-xs tracking-wider px-3 py-2"
                                >
                                    <option v-for="tier in QUALITY_TIERS" :key="tier.name" :value="tier.name">{{ tier.name }}</option>
                                </select>
                            </div>

                            <div v-if="isTenPull">
                                <div class="mb-2 text-[#6bdc9f]/80 text-xs tracking-wider">10 連抽各格品質</div>
                                <div class="grid grid-cols-2 gap-2">
                                    <label v-for="(_, i) in tenPullQualities" :key="i" class="block text-[10px] tracking-wider text-[#6bdc9f]/75">
                                        <div class="mb-1">第 {{ i + 1 }} 格</div>
                                        <select
                                            :value="tenPullQualities[i]"
                                            class="w-full rounded-lg px-2 py-2 text-[10px] bg-[#0f1511] border border-[#2f4739] text-[#6bdc9f]"
                                            @change="tenPullQualities[i] = ($event.target as HTMLSelectElement).value"
                                        >
                                            <option v-for="tier in QUALITY_TIERS" :key="tier.name" :value="tier.name">{{ tier.name }}</option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div v-if="wsAvailable" class="mt-auto pt-4 border-t border-white/5">
                        <p class="text-[10px] text-[#6bdc9f]/30 tracking-widest text-center mb-3">WebSocket 伺服器已開啟</p>
                        <button
                            class="w-full py-2 rounded-xl border border-[#6bdc9f]/30 text-[#6bdc9f] text-xs tracking-widest font-bold hover:bg-[#6bdc9f]/10 transition-colors"
                            @click="mode = 'lobby'; fetchRooms()"
                        >
                            進入大廳
                        </button>
                    </div>
                </template>
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
                    <div class="text-[10px] tracking-[0.4em] text-[#6bdc9f]/50 mb-2 font-bold">DECODING COMPLETE</div>
                    <h3 class="text-white text-2xl font-medium mb-6 tracking-tight">ENTITY_DATA_RECOVERED</h3>
                    <div class="grid grid-cols-2 gap-3 mb-8">
                        <div
                            v-for="(result, i) in lastResults"
                            :key="i"
                            class="bg-black/40 p-4 rounded-xl text-left"
                            :class="result.quality.name === 'legendary' ? 'border border-[#d4af3755]' : 'border border-white/5'"
                        >
                            <div
                                class="text-[8px] tracking-widest font-bold mb-1"
                                :class="{ 'gradient-text': result.quality.name === 'legendary' }"
                                :style="result.quality.name !== 'legendary' ? { color: result.quality.color + 'aa' } : {}"
                            >{{ result.quality.code }}</div>
                            <div
                                class="font-bold text-sm"
                                :class="{ 'gradient-text': result.quality.name === 'legendary' }"
                                :style="result.quality.name !== 'legendary' ? { color: result.quality.color } : {}"
                            >{{ result.code }}</div>
                        </div>
                    </div>
                    <button
                        class="w-full py-4 btn-gradient text-[#0f1511] font-bold rounded-xl hover:brightness-110 transition-all uppercase tracking-widest text-xs"
                        @click="showModal = false"
                    >Acknowledge</button>
                </div>
            </div>
        </Teleport>

        <!-- Join Name Modal (unauthenticated) -->
        <Teleport to="body">
            <div
                v-if="joinTarget"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
                @click.self="joinTarget = null"
            >
                <div class="glass-panel p-8 rounded-3xl max-w-sm w-full shadow-2xl">
                    <div class="text-[10px] tracking-[0.4em] text-[#6bdc9f]/50 mb-2 font-bold">JOIN ROOM</div>
                    <h3 class="text-white text-lg font-medium mb-1 tracking-tight">{{ joinTarget.room_name }}</h3>
                    <p class="text-[#6bdc9f]/40 text-xs tracking-widest mb-6">輸入你的暱稱</p>
                    <input
                        v-model="joinName"
                        type="text"
                        maxlength="30"
                        placeholder="暱稱…"
                        class="w-full bg-transparent border-b border-[#2f4739] focus:border-[#6bdc9f] outline-none text-[#6bdc9f] text-sm pb-1 mb-6 placeholder:text-[#6bdc9f]/30 transition-colors"
                        @keyup.enter="submitJoinModal"
                    />
                    <p v-if="joinError" class="text-red-400 text-xs mb-4 tracking-wide">{{ joinError }}</p>
                    <button
                        :disabled="!joinName.trim() || joinLoading"
                        class="w-full py-3 btn-gradient text-[#0f1511] font-bold rounded-xl hover:brightness-110 transition-all uppercase tracking-widest text-xs disabled:opacity-40"
                        @click="submitJoinModal"
                    >{{ joinLoading ? '加入中…' : '加入' }}</button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup lang="ts">
import Matter from 'matter-js';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { api } from '@/lib/routes';
import { useAuth } from '@/composables/useAuth';

const { Engine, Render, Runner, Bodies, Composite, Events, Body } = Matter;
const { user } = useAuth();

// ── Types ──────────────────────────────────────────────────────────────────
interface QualityTier { code: string; name: string; color: string }
interface DrawResult { quality: QualityTier; code: string }
interface RoomListItem {
    id: number; code: string; room_name: string; status: string;
    players_count: number; max_players: number; can_draw: boolean; is_ten_pull: boolean;
}
type DrawEvent = { player: string; results: DrawResult[]; ts: string };
type PhysicsKey = 'gravity'|'count'|'bounce'|'friction'|'agitationStrength'|'resonanceMs'|'lockMs';

// ── Constants ──────────────────────────────────────────────────────────────
const QUALITY_TIERS: QualityTier[] = [
    { code: 'COMMON_ENTITY',    name: 'common',    color: '#a5d1b4' },
    { code: 'RARE_ENTITY',      name: 'rare',      color: '#00f2ff' },
    { code: 'EPIC_ENTITY',      name: 'epic',      color: '#a855f7' },
    { code: 'LEGENDARY_ENTITY', name: 'legendary', color: '#ffb3b2' },
];

const DEFAULTS: Record<PhysicsKey, number> = {
    gravity: 1.2, count: 25, bounce: 0.9, friction: 0.025,
    agitationStrength: 0.035, resonanceMs: 3000, lockMs: 2000,
};

// ── Mode ───────────────────────────────────────────────────────────────────
const wsAvailable = ref(false);
type Mode = 'standalone' | 'lobby' | 'in-room';
const mode = ref<Mode>('standalone');

// ── Room state ─────────────────────────────────────────────────────────────
const roomList        = ref<RoomListItem[]>([]);
const roomListLoading = ref(false);
const currentRoom     = ref<RoomListItem | null>(null);
const currentPlayer   = ref<{ id: number; name: string } | null>(null);
const isHost          = ref(false);
const drawHistory     = ref<DrawEvent[]>([]);
const broadcastLog    = ref<{ text: string; ts: number }[]>([]);

// ── WebSocket ──────────────────────────────────────────────────────────────
const wsStatus  = ref<'offline'|'connecting'|'connected'>('offline');
const authToken = ref('');
let ws: WebSocket | null = null;
let hbTimer: ReturnType<typeof setInterval> | null = null;
let statusTimer: ReturnType<typeof setInterval> | null = null;

// ── Join modal ─────────────────────────────────────────────────────────────
const joinTarget  = ref<RoomListItem | null>(null);
const joinName    = ref('');
const joinLoading = ref(false);
const joinError   = ref('');

// ── Machine state ──────────────────────────────────────────────────────────
const canDraw        = ref(true);
const isTenPull      = ref(false);
const skipAnim       = ref(false);
const drawsPerUser   = ref(0);
const drawsUsed      = ref(0);
const selectedQuality    = ref('common');
const tenPullQualities   = reactive<string[]>(Array(10).fill('common'));

// ── Physics state ──────────────────────────────────────────────────────────
const chamberEl      = ref<HTMLDivElement>();
const physics        = reactive<Record<PhysicsKey, number>>({ ...DEFAULTS });
const syncing        = ref(false);
const drawLoading    = ref(false);
const extractionDots = ref<Array<{ color: string }>>([]);
const lastResults    = ref<DrawResult[]>([]);
const showModal      = ref(false);
const statusText     = ref('System Ready');

let engine: Matter.Engine;
let render: Matter.Render;
let runner: Matter.Runner;
let agitationHandler: (() => void) | null = null;

// ── Computed ───────────────────────────────────────────────────────────────
const drawsRemaining = computed(() =>
    drawsPerUser.value === 0 ? Infinity : drawsPerUser.value - drawsUsed.value,
);
const drawsExhausted = computed(
    () => drawsPerUser.value > 0 && drawsUsed.value >= drawsPerUser.value,
);
const canPressButton = computed(() => {
    if (mode.value === 'lobby') return false;
    if (syncing.value || drawLoading.value) return false;
    if (mode.value === 'in-room') {
        if (!currentPlayer.value) return false;
        if (!canDraw.value && !isHost.value) return false;
        if (drawsExhausted.value) return false;
    }
    return true;
});

// ── WebSocket ──────────────────────────────────────────────────────────────
function connectWs(roomCode: string) {
    if (ws) return;
    wsStatus.value = 'connecting';
    const proto = location.protocol === 'https:' ? 'wss' : 'ws';
    ws = new WebSocket(`${proto}://${location.host}/ws-lab/gacha/${roomCode}`);

    ws.onopen = () => {
        wsStatus.value = 'connected';
        hbTimer = setInterval(() => ws?.send(JSON.stringify({ type: 'ping' })), 10_000);
        if (currentPlayer.value?.name) {
            ws?.send(JSON.stringify({ type: 'name', name: currentPlayer.value.name }));
        }
        if (authToken.value) {
            ws?.send(JSON.stringify({ type: 'auth', token: authToken.value }));
            authToken.value = '';
        }
    };
    ws.onmessage = (e) => {
        try { handleWsMessage(JSON.parse(e.data)); } catch { /* ignore */ }
    };
    ws.onclose = () => {
        wsStatus.value = 'offline';
        if (hbTimer) { clearInterval(hbTimer); hbTimer = null; }
        ws = null;
    };
    ws.onerror = () => ws?.close();
}

function pushLog(text: string) {
    broadcastLog.value.push({ text, ts: Date.now() });
    if (broadcastLog.value.length > 100) broadcastLog.value.shift();
}

function handleWsMessage(msg: Record<string, any>) {
    switch (msg.type) {
        case 'machine_state': {
            canDraw.value      = msg.can_draw === 'true';
            isTenPull.value    = msg.is_ten_pull === 'true';
            skipAnim.value     = msg.skip_anim === 'true';
            drawsPerUser.value = parseInt(msg.draws_per_user ?? '0');
            const flags = [
                canDraw.value ? '抽卡開放' : '抽卡鎖定',
                isTenPull.value ? '10連抽' : '單抽',
                skipAnim.value ? 'SKIP ANIM' : null,
                drawsPerUser.value > 0 ? `上限 ${drawsPerUser.value} 次` : null,
            ].filter(Boolean).join(' · ');
            pushLog(`⚙ 機台設定更新：${flags}`);
            break;
        }
        case 'player_joined':
            pushLog(`→ ${msg.name} 加入房間`);
            break;
        case 'player_left':
            pushLog(`← ${msg.name} 離開房間`);
            break;
        case 'draw_result':
            drawHistory.value.push({ player: msg.player, results: msg.results, ts: msg.ts });
            if (drawHistory.value.length > 50) drawHistory.value.shift();
            pushLog(`🎰 ${msg.player} 抽了 ${msg.results?.length ?? 1} 張`);
            break;
        case 'room_closed':
            pushLog('⚠ 房間已關閉');
            disconnectWs();
            currentRoom.value = currentPlayer.value = null;
            isHost.value = false;
            mode.value = 'lobby';
            fetchRooms();
            break;
        case 'server_shutdown':
            pushLog('⚠ 伺服器關閉');
            disconnectWs();
            currentRoom.value = currentPlayer.value = null;
            isHost.value = false;
            wsAvailable.value = false;
            mode.value = 'standalone';
            break;
    }
}

function disconnectWs() {
    if (hbTimer) { clearInterval(hbTimer); hbTimer = null; }
    ws?.close();
    ws = null;
    wsStatus.value = 'offline';
}

function sendMachineState() {
    if (!ws || wsStatus.value !== 'connected' || !isHost.value) return;
    ws.send(JSON.stringify({
        type:           'machine_state',
        can_draw:       String(canDraw.value),
        is_ten_pull:    String(isTenPull.value),
        skip_anim:      String(skipAnim.value),
        draws_per_user: String(drawsPerUser.value),
    }));
}

// ── Room actions ───────────────────────────────────────────────────────────
async function fetchRooms() {
    roomListLoading.value = true;
    try {
        const res = await fetch(api.gacha.rooms(), { credentials: 'include' });
        if (res.ok) roomList.value = await res.json();
    } finally {
        roomListLoading.value = false;
    }
}

async function createRoom() {
    if (!user.value) return;
    const res = await fetch(api.gacha.store(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
    }).catch(() => null);
    if (!res?.ok) return;
    const data = await res.json();
    currentRoom.value   = { ...data.room, players_count: 1 } as RoomListItem;
    currentPlayer.value = { id: data.room.id, name: user.value.name };
    isHost.value  = true;
    drawsUsed.value = 0;
    drawHistory.value = [];
    broadcastLog.value = [];
    pushLog(`✓ 已建立房間 ${data.room.code}`);
    mode.value = 'in-room';
    connectWs(data.room.code);
}

function openJoinModal(room: RoomListItem) {
    joinTarget.value = room;
    joinName.value = user.value?.name ?? '';
    joinError.value = '';
}

async function submitJoinModal() {
    if (!joinTarget.value || !joinName.value.trim()) return;
    await doJoin(joinTarget.value, joinName.value.trim());
}

async function doJoin(room: RoomListItem, name: string) {
    joinLoading.value = true;
    try {
        const res = await fetch(api.gacha.join(room.code), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ name }),
        });
        if (!res.ok) {
            const msg = res.status === 404 ? '房間不存在' : res.status === 422 ? '房間已滿' : '加入失敗';
            joinError.value = msg;
            fetchRooms();
            return;
        }
        joinTarget.value = null;
        joinError.value = '';
        const data = await res.json();
        currentRoom.value   = room;
        currentPlayer.value = { id: data.player_id, name };
        isHost.value    = false;
        drawsUsed.value = 0;
        canDraw.value   = room.can_draw;
        isTenPull.value = room.is_ten_pull;
        drawHistory.value = [];
        broadcastLog.value = [];
        pushLog(`✓ 已加入房間 ${room.code}`);
        mode.value = 'in-room';
        connectWs(room.code);
    } finally {
        joinLoading.value = false;
    }
}

async function leaveRoom() {
    if (!currentRoom.value) return;
    if (isHost.value) {
        fetch(api.gacha.destroy(currentRoom.value.code), {
            method: 'DELETE', credentials: 'include',
        }).catch(() => {});
    }
    disconnectWs();
    currentRoom.value = currentPlayer.value = null;
    isHost.value = false;
    drawHistory.value = [];
    mode.value = 'lobby';
    fetchRooms();
}

// ── Draw ───────────────────────────────────────────────────────────────────
async function startSync() {
    if (!canPressButton.value) return;
    syncing.value = true;
    extractionDots.value = [];

    if (!skipAnim.value) {
        statusText.value = 'Active Resonance...';
        agitationHandler = applyAgitation;
        Events.on(engine, 'afterUpdate', agitationHandler);
        await delay(physics.resonanceMs);
        Events.off(engine, 'afterUpdate', agitationHandler);
        agitationHandler = null;
        statusText.value = 'Vector Locked...';
        await delay(physics.lockMs);
    }

    statusText.value = 'Ejecting...';

    if (mode.value === 'in-room' && currentRoom.value && currentPlayer.value) {
        await drawFromApi();
    } else {
        resolveStandalone();
    }
}

async function drawFromApi() {
    drawLoading.value = true;
    try {
        const res = await fetch(api.gacha.draw(currentRoom.value!.code), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ player_id: currentPlayer.value!.id }),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            statusText.value = (err as any).message ?? 'Error';
            syncing.value = false;
            return;
        }
        const data = await res.json();
        drawsUsed.value += isTenPull.value ? 10 : 1;
        showResults(data.results);
    } catch {
        statusText.value = 'Network error';
        syncing.value = false;
    } finally {
        drawLoading.value = false;
    }
}

function resolveStandalone() {
    const count = isTenPull.value ? 10 : 1;
    const results: DrawResult[] = Array.from({ length: count }, (_, i) => {
        const qualityName = count === 10 ? tenPullQualities[i] : selectedQuality.value;
        const tier = QUALITY_TIERS.find(q => q.name === qualityName) ?? QUALITY_TIERS[0];
        return { quality: tier, code: `V-SYNC_${Math.floor(Math.random() * 9000) + 1000}` };
    });
    showResults(results);
}

function showResults(results: DrawResult[]) {
    lastResults.value = results;
    extractionDots.value = results.map(r => ({ color: r.quality.color }));
    setTimeout(() => {
        showModal.value = true;
        syncing.value = false;
        statusText.value = 'System Ready';
    }, 600);
}

function delay(ms: number) { return new Promise<void>(resolve => setTimeout(resolve, ms)); }

// ── Physics ────────────────────────────────────────────────────────────────
function applyAgitation() {
    Composite.allBodies(engine.world).forEach(body => {
        if (!body.isStatic) {
            Body.applyForce(body, body.position, {
                x: (Math.random() - 0.5) * physics.agitationStrength,
                y: (Math.random() - 0.5) * physics.agitationStrength,
            });
        }
    });
}

function initPhysics() {
    const el = chamberEl.value!;
    const cw = el.clientWidth;
    const ch = el.clientHeight;
    engine = Engine.create();
    engine.gravity.y = physics.gravity;
    render = Render.create({
        element: el, engine,
        options: { width: cw, height: ch, wireframes: false, background: 'transparent' },
    });
    const wall = { isStatic: true, render: { visible: false } };
    Composite.add(engine.world, [
        Bodies.rectangle(cw / 2, -1000, 4000, 2000, wall),
        Bodies.rectangle(cw / 2, ch + 1000, 4000, 2000, wall),
        Bodies.rectangle(-1000, ch / 2, 2000, 4000, wall),
        Bodies.rectangle(cw + 1000, ch / 2, 2000, 4000, wall),
    ]);
    for (let i = 0; i < physics.count; i++) {
        Composite.add(engine.world, Bodies.circle(
            Math.random() * (cw - 20) + 10, Math.random() * (ch - 20) + 10, 7,
            { restitution: physics.bounce, frictionAir: physics.friction,
              render: { fillStyle: '#6bdc9f', strokeStyle: '#ffffff22', lineWidth: 2 } },
        ));
    }
    Render.run(render);
    runner = Runner.create();
    Runner.run(runner, engine);
}

// ── Lifecycle ──────────────────────────────────────────────────────────────
async function checkStatus() {
    try {
        const res = await fetch(api.wsLab.status(), { credentials: 'include' });
        if (!res.ok) return;
        const d = await res.json();
        const wasAvailable = wsAvailable.value;
        wsAvailable.value = d.running;

        if (!wasAvailable && d.running && mode.value === 'standalone') {
            mode.value = 'lobby';
            fetchRooms();
        } else if (!d.running && mode.value !== 'standalone') {
            disconnectWs();
            currentRoom.value = currentPlayer.value = null;
            isHost.value = false;
            mode.value = 'standalone';
        }
    } catch { /* ignore */ }
}

onMounted(async () => {
    initPhysics();

    const [, tokenRes] = await Promise.all([
        checkStatus(),
        user.value
            ? fetch(api.wsLab.authToken(), { method: 'POST', credentials: 'include' })
            : Promise.resolve(null),
    ]);

    if (tokenRes?.ok) {
        const d = await tokenRes.json();
        authToken.value = d.token;
    }

    statusTimer = setInterval(checkStatus, 10_000);
});

onUnmounted(() => {
    if (statusTimer) { clearInterval(statusTimer); statusTimer = null; }
    disconnectWs();
    if (agitationHandler) Events.off(engine, 'afterUpdate', agitationHandler);
    if (runner) Runner.stop(runner);
    if (render) Render.stop(render);
    if (engine) Engine.clear(engine);
});
</script>

<style scoped>
.emerald-glow { box-shadow: 0 0 30px rgba(107, 220, 159, 0.15); }
.btn-gradient { background: linear-gradient(145deg, #6bdc9f 0%, #2ca46d 100%); }
.tune-slider { accent-color: #6bdc9f; }
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
    0%   { transform: scale(0.3); opacity: 0; }
    50%  { transform: scale(1.05); opacity: 0.8; }
    70%  { transform: scale(0.9); opacity: 0.9; }
    100% { transform: scale(1); opacity: 1; }
}
.animate-bounce-in { animation: bounce-in 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; }
</style>
