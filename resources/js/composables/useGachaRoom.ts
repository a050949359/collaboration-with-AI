import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import type { Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/lib/routes';

export interface QualityTier {
    code: string;
    name: string;
}
export interface DrawResultCard {
    id: number;
    name: string;
    image_url: string | null;
}
export interface DrawResult {
    quality: QualityTier;
    code: string;
    card?: DrawResultCard;
}
export interface RoomListItem {
    id: number;
    code: string;
    room_name: string;
    status: string;
    players_count: number;
    max_players: number;
}
export type DrawEvent = { player: string; results: DrawResult[]; ts: string };
export type Mode = 'standalone' | 'lobby' | 'in-room';

export const QUALITY_TIERS: QualityTier[] = [
    { code: 'COMMON_ENTITY', name: 'common' },
    { code: 'RARE_ENTITY', name: 'rare' },
    { code: 'EPIC_ENTITY', name: 'epic' },
    { code: 'LEGENDARY_ENTITY', name: 'legendary' },
];

export function useGachaRoom(
    user: Ref<{ name: string } | null>,
    runAnimation: (setStatus: (s: string) => void) => Promise<void>,
) {
    const { t } = useI18n();

    // ── Mode ───────────────────────────────────────────────────────────────
    const wsAvailable = ref(false);
    const mode = ref<Mode>('standalone');

    // ── Room state ─────────────────────────────────────────────────────────
    const roomList = ref<RoomListItem[]>([]);
    const roomListLoading = ref(false);
    const currentRoom = ref<RoomListItem | null>(null);
    const currentPlayer = ref<{ id: number; name: string } | null>(null);
    const isHost = ref(false);
    const drawHistory = ref<DrawEvent[]>([]);
    const broadcastLog = ref<{ text: string; ts: number }[]>([]);

    // ── WebSocket ──────────────────────────────────────────────────────────
    const wsStatus = ref<'offline' | 'connecting' | 'connected'>('offline');
    const authToken = ref('');
    let ws: WebSocket | null = null;
    let hbTimer: ReturnType<typeof setInterval> | null = null;
    let statusTimer: ReturnType<typeof setInterval> | null = null;
    let welcomeTimer: ReturnType<typeof setTimeout> | null = null;

    // ── Decks ──────────────────────────────────────────────────────────────
    interface GachaCard {
        id: number;
        name: string;
        rarity: string;
        weight: number;
    }
    interface GachaDeck {
        id: number;
        name: string;
        cards: GachaCard[];
    }
    const allDecks = ref<GachaDeck[]>([]);
    const selectedDeckId = ref<number | null>(null);

    async function fetchDecks() {
        const res = await fetch(api.gacha.decks(), {
            credentials: 'include',
        }).catch(() => null);

        if (res?.ok) {
            allDecks.value = await res.json();
        }
    }

    // ── Create modal ───────────────────────────────────────────────────────
    const showCreateModal = ref(false);
    const createName = ref('');

    // ── Join modal ─────────────────────────────────────────────────────────
    const joinTarget = ref<RoomListItem | null>(null);
    const joinName = ref('');
    const joinLoading = ref(false);
    const joinError = ref('');

    // ── Machine state ──────────────────────────────────────────────────────
    const canDraw = ref(true);
    const isTenPull = ref(false);
    const skipAnim = ref(false);
    const drawsPerUser = ref(0);
    const drawsUsed = ref(0);
    const selectedQuality = ref('common');
    const tenPullQualities = reactive<string[]>(Array(10).fill('common'));

    // ── Draw / UI state ────────────────────────────────────────────────────
    const syncing = ref(false);
    const drawLoading = ref(false);
    const extractionDots = ref<Array<{ color: string }>>([]);
    const lastResults = ref<DrawResult[]>([]);
    const showModal = ref(false);
    const statusKey = ref('gacha.system_ready');
    const statusOverride = ref('');
    const statusText = computed(
        () => statusOverride.value || t(statusKey.value),
    );

    // ── Computed ───────────────────────────────────────────────────────────
    const drawsRemaining = computed(() =>
        drawsPerUser.value === 0
            ? Infinity
            : drawsPerUser.value - drawsUsed.value,
    );
    const drawsExhausted = computed(
        () => drawsPerUser.value > 0 && drawsUsed.value >= drawsPerUser.value,
    );
    const canPressButton = computed(() => {
        if (mode.value === 'lobby') {
            return false;
        }

        if (syncing.value || drawLoading.value) {
            return false;
        }

        if (mode.value === 'in-room') {
            if (!currentPlayer.value) {
                return false;
            }

            if (!canDraw.value && !isHost.value) {
                return false;
            }

            if (drawsExhausted.value) {
                return false;
            }
        }

        return true;
    });

    // ── Helpers ────────────────────────────────────────────────────────────
    function pushLog(text: string) {
        broadcastLog.value.push({ text, ts: Date.now() });

        if (broadcastLog.value.length > 100) {
            broadcastLog.value.shift();
        }
    }

    function resetMachineState() {
        canDraw.value = true;
        isTenPull.value = false;
        skipAnim.value = false;
        drawsPerUser.value = 0;
        drawsUsed.value = 0;
    }

    // ── WebSocket ──────────────────────────────────────────────────────────
    function connectWs(roomCode: string) {
        if (ws) {
            return;
        }

        wsStatus.value = 'connecting';
        const proto = location.protocol === 'https:' ? 'wss' : 'ws';
        ws = new WebSocket(
            `${proto}://${location.host}/ws-lab/gacha/${roomCode}`,
        );

        welcomeTimer = setTimeout(() => {
            welcomeTimer = null;

            if (mode.value !== 'in-room') {
                return;
            }

            currentRoom.value = currentPlayer.value = null;
            isHost.value = false;
            mode.value = 'lobby';
            ws?.close();
            fetchRooms();
        }, 3000);

        ws.onopen = () => {
            wsStatus.value = 'connected';
            hbTimer = setInterval(
                () => ws?.send(JSON.stringify({ type: 'ping' })),
                10_000,
            );

            if (currentPlayer.value?.name) {
                ws?.send(
                    JSON.stringify({
                        type: 'name',
                        name: currentPlayer.value.name,
                    }),
                );
            }

            if (authToken.value) {
                ws?.send(
                    JSON.stringify({ type: 'auth', token: authToken.value }),
                );
                authToken.value = '';
            }
        };
        ws.onmessage = (e) => {
            try {
                const msg = JSON.parse(e.data);

                if (msg.type === 'welcome') {
                    if (welcomeTimer) {
                        clearTimeout(welcomeTimer);
                        welcomeTimer = null;
                    }

                    return;
                }

                handleWsMessage(msg);
            } catch {
                /* ignore */
            }
        };
        ws.onclose = () => {
            wsStatus.value = 'offline';

            if (hbTimer) {
                clearInterval(hbTimer);
                hbTimer = null;
            }

            ws = null;
        };
        ws.onerror = () => ws?.close();
    }

    function disconnectWs() {
        if (welcomeTimer) {
            clearTimeout(welcomeTimer);
            welcomeTimer = null;
        }

        if (hbTimer) {
            clearInterval(hbTimer);
            hbTimer = null;
        }

        ws?.close();
        ws = null;
        wsStatus.value = 'offline';
    }

    function handleWsMessage(msg: Record<string, any>) {
        switch (msg.type) {
            case 'machine_state': {
                canDraw.value = msg.can_draw === 'true';
                isTenPull.value = msg.is_ten_pull === 'true';
                skipAnim.value = msg.skip_anim === 'true';
                drawsPerUser.value = parseInt(msg.draws_per_user ?? '0');
                const flags = [
                    canDraw.value ? '抽卡開放' : '抽卡鎖定',
                    isTenPull.value ? '10連抽' : '單抽',
                    skipAnim.value ? 'SKIP ANIM' : null,
                    drawsPerUser.value > 0
                        ? `上限 ${drawsPerUser.value} 次`
                        : null,
                ]
                    .filter(Boolean)
                    .join(' · ');
                pushLog(`⚙ 機台設定更新：${flags}`);
                break;
            }
            case 'player_joined':
                pushLog(`→ ${msg.name} 加入房間`);
                break;
            case 'player_left':
                pushLog(`← ${msg.name} 離開房間`);
                break;
            case 'draw_result': {
                const results: DrawResult[] = msg.results ?? [];
                drawHistory.value.push({
                    player: msg.player,
                    results,
                    ts: msg.ts,
                });

                if (drawHistory.value.length > 50) {
                    drawHistory.value.shift();
                }

                pushLog(`🎰 ${msg.player} 抽了 ${results.length} 張`);

                if (drawResultWaiter) {
                    drawsUsed.value += isTenPull.value ? 10 : 1;
                    drawResultWaiter(results);
                    drawResultWaiter = null;
                } else {
                    triggerRemoteAnimation(results);
                }

                break;
            }
            case 'draws_reset':
                drawsUsed.value = 0;
                pushLog('↺ 抽卡次數已重置');
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

    function sendMachineState() {
        if (!ws || wsStatus.value !== 'connected' || !isHost.value) {
            return;
        }

        ws.send(
            JSON.stringify({
                type: 'machine_state',
                can_draw: String(canDraw.value),
                is_ten_pull: String(isTenPull.value),
                skip_anim: String(skipAnim.value),
                draws_per_user: String(drawsPerUser.value),
            }),
        );
    }

    // ── Room actions ───────────────────────────────────────────────────────
    async function fetchRooms() {
        roomListLoading.value = true;

        try {
            const res = await fetch(api.gacha.rooms(), {
                credentials: 'include',
            });

            if (res.ok) {
                roomList.value = await res.json();
            }
        } finally {
            roomListLoading.value = false;
        }
    }

    async function fetchAuthToken() {
        if (!user.value) {
            return;
        }

        try {
            const res = await fetch(api.wsLab.authToken(), {
                method: 'POST',
                credentials: 'include',
            });

            if (res.ok) {
                const d = await res.json();
                authToken.value = d.token;
            }
        } catch {
            /* ignore */
        }
    }

    function openCreateModal() {
        createName.value = user.value?.name ?? '';
        selectedDeckId.value = null;
        fetchDecks();
        showCreateModal.value = true;
    }

    async function submitCreateModal() {
        if (!createName.value.trim()) {
            return;
        }

        await createRoom(createName.value.trim());
    }

    async function createRoom(name: string) {
        if (!user.value) {
            return;
        }

        showCreateModal.value = false;
        const res = await fetch(api.gacha.store(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                player_name: name,
                ...(selectedDeckId.value !== null && {
                    deck_id: selectedDeckId.value,
                }),
            }),
        }).catch(() => null);

        if (!res?.ok) {
            return;
        }

        const data = await res.json();
        currentRoom.value = { ...data.room, players_count: 1 } as RoomListItem;
        currentPlayer.value = { id: data.player_id, name };
        isHost.value = true;
        drawsUsed.value = 0;
        drawHistory.value = [];
        broadcastLog.value = [];
        pushLog(`✓ 已建立房間 ${data.room.code}`);
        mode.value = 'in-room';
        await fetchAuthToken();
        connectWs(data.room.code);
    }

    function openJoinModal(room: RoomListItem) {
        joinTarget.value = room;
        joinName.value = user.value?.name ?? '';
        joinError.value = '';
    }

    async function submitJoinModal() {
        if (!joinTarget.value || !joinName.value.trim()) {
            return;
        }

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
                joinError.value =
                    res.status === 404
                        ? t('gacha.join_not_found')
                        : res.status === 422
                          ? t('gacha.join_full')
                          : t('gacha.join_failed');
                fetchRooms();

                return;
            }

            joinTarget.value = null;
            joinError.value = '';
            const data = await res.json();
            currentRoom.value = room;
            currentPlayer.value = { id: data.player_id, name };
            isHost.value = false;
            drawsUsed.value = 0;
            drawHistory.value = [];
            broadcastLog.value = [];
            pushLog(`✓ 已加入房間 ${room.code}`);
            mode.value = 'in-room';
            await fetchAuthToken();
            connectWs(room.code);
        } finally {
            joinLoading.value = false;
        }
    }

    async function leaveRoom() {
        if (!currentRoom.value) {
            return;
        }

        if (isHost.value) {
            fetch(api.gacha.destroy(currentRoom.value.code), {
                method: 'DELETE',
                credentials: 'include',
            }).catch(() => {});
        }

        disconnectWs();
        currentRoom.value = currentPlayer.value = null;
        isHost.value = false;
        drawHistory.value = [];
        broadcastLog.value = [];
        resetMachineState();
        mode.value = 'lobby';
        fetchRooms();
    }

    async function resetAllDraws() {
        if (!currentRoom.value) {
            return;
        }

        const res = await fetch(api.gacha.resetDraws(currentRoom.value.code), {
            method: 'POST',
            credentials: 'include',
        });

        if (!res.ok) {
            pushLog('⚠ 重置失敗');
        }
    }

    // ── Draw ───────────────────────────────────────────────────────────────
    let drawResultWaiter: ((r: DrawResult[]) => void) | null = null;

    function showResults(results: DrawResult[]) {
        lastResults.value = results;
        extractionDots.value = results.map((r) => ({
            color: `var(--rarity-${r.quality.name})`,
        }));
        setTimeout(() => {
            showModal.value = true;
            syncing.value = false;
            statusKey.value = 'gacha.system_ready';
            statusOverride.value = '';
        }, 600);
    }

    async function triggerRemoteAnimation(results: DrawResult[]) {
        if (syncing.value) {
            return;
        }

        syncing.value = true;
        extractionDots.value = [];

        if (!skipAnim.value) {
            await runAnimation((s) => {
                statusOverride.value = s;
                statusKey.value = '';
            });
        }

        showResults(results);
    }

    async function drawFromApi() {
        drawLoading.value = true;

        try {
            const res = await fetch(api.gacha.draw(currentRoom.value!.code), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                // 機台狀態（can_draw / draws_per_user / is_ten_pull）由後端向
                // ws server 查 host 設定的 machine_state，這裡只需帶 player_id。
                body: JSON.stringify({
                    player_id: currentPlayer.value!.id,
                }),
            });

            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                const msg = (err as any).message;

                if (msg) {
                    statusOverride.value = msg;
                    statusKey.value = '';
                } else {
                    statusKey.value = 'gacha.network_error';
                    statusOverride.value = '';
                }

                drawResultWaiter = null;
                syncing.value = false;
            }
        } catch {
            statusKey.value = 'gacha.network_error';
            statusOverride.value = '';
            drawResultWaiter = null;
            syncing.value = false;
        } finally {
            drawLoading.value = false;
        }
    }

    function resolveStandalone() {
        const count = isTenPull.value ? 10 : 1;
        const results: DrawResult[] = Array.from({ length: count }, (_, i) => {
            const qualityName =
                count === 10 ? tenPullQualities[i] : selectedQuality.value;
            const tier =
                QUALITY_TIERS.find((q) => q.name === qualityName) ??
                QUALITY_TIERS[0];

            return {
                quality: tier,
                code: `V-SYNC_${Math.floor(Math.random() * 9000) + 1000}`,
            };
        });
        showResults(results);
    }

    async function startSync() {
        if (!canPressButton.value) {
            return;
        }

        syncing.value = true;
        extractionDots.value = [];

        if (
            mode.value === 'in-room' &&
            currentRoom.value &&
            currentPlayer.value
        ) {
            const resultPromise = new Promise<DrawResult[]>((resolve) => {
                drawResultWaiter = resolve;
            });
            drawFromApi();

            if (!skipAnim.value) {
                await runAnimation((s) => {
                    statusOverride.value = s;
                    statusKey.value = '';
                });
            } else {
                statusKey.value = 'gacha.ejecting';
                statusOverride.value = '';
            }

            const results = await resultPromise;
            showResults(results);
        } else {
            if (!skipAnim.value) {
                await runAnimation((s) => {
                    statusOverride.value = s;
                    statusKey.value = '';
                });
            } else {
                statusKey.value = 'gacha.ejecting';
                statusOverride.value = '';
            }

            resolveStandalone();
        }
    }

    // ── Status polling ─────────────────────────────────────────────────────
    async function checkStatus() {
        try {
            const res = await fetch(api.wsLab.status(), {
                credentials: 'include',
            });

            if (!res.ok) {
                return;
            }

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
        } catch {
            /* ignore */
        }
    }

    onMounted(async () => {
        await checkStatus();
        statusTimer = setInterval(checkStatus, 10_000);
    });

    onUnmounted(() => {
        if (statusTimer) {
            clearInterval(statusTimer);
            statusTimer = null;
        }

        disconnectWs();
    });

    return {
        mode,
        wsAvailable,
        currentRoom,
        currentPlayer,
        isHost,
        roomList,
        roomListLoading,
        wsStatus,
        showCreateModal,
        createName,
        openCreateModal,
        submitCreateModal,
        joinTarget,
        joinName,
        joinLoading,
        joinError,
        openJoinModal,
        submitJoinModal,
        canDraw,
        isTenPull,
        skipAnim,
        drawsPerUser,
        drawsUsed,
        selectedQuality,
        tenPullQualities,
        drawHistory,
        broadcastLog,
        syncing,
        drawLoading,
        extractionDots,
        lastResults,
        showModal,
        statusText,
        drawsRemaining,
        drawsExhausted,
        canPressButton,
        sendMachineState,
        leaveRoom,
        resetAllDraws,
        startSync,
        fetchRooms,
        allDecks,
        selectedDeckId,
    };
}
