<template>
    <AppLayout>
        <div
            class="mx-auto flex max-w-7xl flex-col items-start justify-center gap-4 px-4 pt-24 pb-8 lg:flex-row lg:gap-6 lg:px-8"
        >
            <!-- Right Panel: Physics Chamber -->
            <div
                class="emerald-glow relative flex w-full flex-col rounded-2xl border border-white/5 bg-[#151c17] p-3 lg:order-last lg:flex-[4] lg:p-5"
            >
                <!-- Resonance Chamber -->
                <div
                    ref="chamberEl"
                    class="relative mb-5 h-36 w-full overflow-hidden rounded-xl border-2 border-[#343b36] bg-[#0a100c] shadow-inner"
                />

                <!-- Sync Button -->
                <div class="flex flex-col items-center gap-4">
                    <button
                        :disabled="!canPressButton"
                        class="group relative flex h-[72px] w-[72px] items-center justify-center rounded-full border-4 border-[#343b36] bg-[#1c251f] transition-all hover:border-[#6bdc9f]/50 active:scale-90 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="startSync"
                    >
                        <div
                            class="h-10 w-1 rounded-full bg-[#6bdc9f] transition-transform duration-700 ease-in-out"
                            :style="{
                                transform: syncing
                                    ? 'rotate(180deg)'
                                    : 'rotate(0deg)',
                            }"
                        />
                        <div
                            class="absolute inset-0 rounded-full bg-[#6bdc9f]/5 opacity-0 transition-opacity group-hover:opacity-100"
                        />
                    </button>
                </div>

                <!-- Extraction Port -->
                <div
                    class="mt-7 mb-4 grid min-h-[48px] grid-cols-5 gap-1.5 rounded-xl border border-white/5 bg-black/30 p-3"
                >
                    <div
                        v-for="(dot, i) in extractionDots"
                        :key="i"
                        class="emerald-glow animate-bounce-in mx-auto h-5 w-5 rounded-full border border-white/10"
                        :style="{ backgroundColor: dot.color }"
                    />
                </div>

                <div class="mt-auto w-full text-center">
                    <h2
                        class="mb-2 text-[10px] font-bold tracking-[0.3em] text-[#6bdc9f] uppercase"
                    >
                        {{ statusText }}
                    </h2>

                    <div
                        v-if="drawsPerUser > 0"
                        class="mb-1.5 text-[9px] font-bold tracking-widest"
                        :class="
                            drawsRemaining > 0
                                ? 'text-[#6bdc9f]/70'
                                : 'text-red-400/70'
                        "
                    >
                        {{ t('gacha.draws_label') }}：{{ drawsRemaining }} /
                        {{ drawsPerUser }}
                    </div>

                    <div
                        class="mb-2 flex justify-center gap-3 text-[9px] font-medium tracking-widest text-[#6bdc9f]/40"
                    >
                        <span v-if="isTenPull">{{
                            t('gacha.ten_sync_badge')
                        }}</span>
                        <span v-if="skipAnim">{{
                            t('gacha.skip_anim_badge')
                        }}</span>
                        <span
                            v-if="mode === 'in-room' && !canDraw && !isHost"
                            class="text-red-400/60"
                            >{{ t('gacha.locked_badge') }}</span
                        >
                    </div>

                    <button
                        :disabled="lastResults.length === 0"
                        class="rounded-lg border border-[#2f4739] bg-[#1d2a22] px-3 py-1.5 text-[9px] font-bold tracking-[0.2em] text-[#6bdc9f] transition-colors hover:bg-[#233328] disabled:cursor-not-allowed disabled:opacity-40"
                        @click="showModal = true"
                    >
                        {{ t('gacha.show_results') }}
                    </button>
                </div>

                <!-- In-room status bar -->
                <div
                    v-if="mode === 'in-room' && currentRoom"
                    class="mt-4 border-t border-white/5 pt-4"
                >
                    <div
                        class="flex items-center justify-between font-mono text-[10px]"
                    >
                        <div>
                            <span class="tracking-widest text-[#6bdc9f]/50"
                                >ROOM
                            </span>
                            <span class="font-bold text-[#6bdc9f]">{{
                                currentRoom.code
                            }}</span>
                            <span v-if="isHost" class="ml-2 text-[#6bdc9f]/50"
                                >[HOST]</span
                            >
                        </div>
                        <span
                            class="rounded px-2 py-px text-[9px] tracking-widest"
                            :class="
                                wsStatus === 'connected'
                                    ? 'text-[#6bdc9f]/70'
                                    : 'animate-pulse text-[#6bdc9f]/30'
                            "
                            >{{ wsStatus }}</span
                        >
                    </div>
                    <button
                        class="mt-2 w-full rounded-lg border border-red-900/50 py-1.5 text-[9px] font-bold tracking-widest text-red-400/70 transition-colors hover:border-red-400/50 hover:text-red-400"
                        @click="leaveRoom"
                    >
                        {{
                            isHost
                                ? t('gacha.close_room')
                                : t('gacha.leave_room')
                        }}
                    </button>
                </div>
            </div>

            <!-- Right Panel -->
            <aside
                class="emerald-glow flex w-full min-w-0 flex-col gap-6 overflow-auto rounded-3xl border border-white/5 bg-[#131a15] p-5 lg:flex-[6] lg:p-6"
            >
                <!-- LOBBY -->
                <template v-if="mode === 'lobby'">
                    <div class="flex items-center justify-between">
                        <div>
                            <div
                                class="mb-1 text-[10px] font-bold tracking-[0.35em] text-[#6bdc9f]/55"
                            >
                                {{ t('gacha.gacha_rooms_label') }}
                            </div>
                            <h3
                                class="text-xl font-semibold tracking-tight text-white"
                            >
                                {{ t('gacha.room_list_title') }}
                            </h3>
                        </div>
                        <button
                            v-if="user"
                            class="rounded-xl border border-[#2f4739] bg-[#1d2a22] px-4 py-2 text-xs font-bold tracking-widest text-[#6bdc9f] transition-colors hover:bg-[#233328]"
                            @click="openCreateModal"
                        >
                            {{ t('gacha.create_room') }}
                        </button>
                    </div>

                    <div
                        v-if="roomListLoading"
                        class="py-8 text-center text-xs tracking-widest text-[#6bdc9f]/30"
                    >
                        {{ t('gacha.loading') }}
                    </div>
                    <div
                        v-else-if="roomList.length === 0"
                        class="py-8 text-center text-xs tracking-widest text-[#6bdc9f]/30"
                    >
                        {{ t('gacha.no_rooms') }}
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <div
                            v-for="room in roomList"
                            :key="room.id"
                            class="flex items-center gap-4 rounded-2xl border border-white/5 bg-black/30 p-4 transition-colors hover:border-[#6bdc9f]/20"
                        >
                            <div class="min-w-0 flex-1">
                                <div
                                    class="truncate text-sm font-semibold text-white"
                                >
                                    {{ room.room_name }}
                                </div>
                                <div
                                    class="mt-0.5 flex gap-3 text-[10px] tracking-widest text-[#6bdc9f]/40"
                                >
                                    <span>{{ room.code }}</span>
                                    <span
                                        >{{ room.players_count }}/{{
                                            room.max_players
                                        }}</span
                                    >
                                </div>
                            </div>
                            <button
                                class="shrink-0 rounded-xl border border-[#6bdc9f]/30 px-4 py-1.5 text-xs font-bold tracking-widest text-[#6bdc9f] transition-colors hover:bg-[#6bdc9f]/10"
                                @click="openJoinModal(room)"
                            >
                                {{ t('gacha.join') }}
                            </button>
                        </div>
                    </div>

                    <button
                        class="mt-auto text-[10px] tracking-widest text-[#6bdc9f]/30 transition-colors hover:text-[#6bdc9f]/60"
                        :disabled="roomListLoading"
                        @click="fetchRooms"
                    >
                        {{ t('gacha.refresh') }}
                    </button>
                </template>

                <!-- IN-ROOM: HOST CONTROLS + DRAW HISTORY -->
                <template v-else-if="mode === 'in-room'">
                    <!-- Machine controls (host only) -->
                    <section v-if="isHost">
                        <div
                            class="mb-2 text-[10px] font-bold tracking-[0.35em] text-[#6bdc9f]/55"
                        >
                            {{ t('gacha.host_control') }}
                        </div>
                        <h3
                            class="mb-4 text-xl font-semibold tracking-tight text-white"
                        >
                            {{ t('gacha.machine_control') }}
                        </h3>

                        <div class="space-y-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-xs tracking-wider text-[#6bdc9f]/80"
                                    >{{ t('gacha.allow_draw') }}</span
                                >
                                <button
                                    class="relative h-6 w-12 rounded-full transition-colors"
                                    :class="
                                        canDraw
                                            ? 'bg-[#6bdc9f]'
                                            : 'bg-[#2f4739]'
                                    "
                                    @click="
                                        canDraw = !canDraw;
                                        sendMachineState();
                                    "
                                >
                                    <span
                                        class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                                        :class="canDraw ? 'translate-x-6' : ''"
                                    />
                                </button>
                            </div>

                            <div class="flex items-center justify-between">
                                <span
                                    class="text-xs tracking-wider text-[#6bdc9f]/80"
                                    >{{ t('gacha.ten_sync_mode') }}</span
                                >
                                <button
                                    class="relative h-6 w-12 rounded-full transition-colors"
                                    :class="
                                        isTenPull
                                            ? 'bg-[#6bdc9f]'
                                            : 'bg-[#2f4739]'
                                    "
                                    @click="
                                        isTenPull = !isTenPull;
                                        sendMachineState();
                                    "
                                >
                                    <span
                                        class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                                        :class="
                                            isTenPull ? 'translate-x-6' : ''
                                        "
                                    />
                                </button>
                            </div>

                            <div class="flex items-center justify-between">
                                <span
                                    class="text-xs tracking-wider text-[#6bdc9f]/80"
                                    >{{ t('gacha.skip_animation') }}</span
                                >
                                <button
                                    class="relative h-6 w-12 rounded-full transition-colors"
                                    :class="
                                        skipAnim
                                            ? 'bg-[#6bdc9f]'
                                            : 'bg-[#2f4739]'
                                    "
                                    @click="
                                        skipAnim = !skipAnim;
                                        sendMachineState();
                                    "
                                >
                                    <span
                                        class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                                        :class="skipAnim ? 'translate-x-6' : ''"
                                    />
                                </button>
                            </div>

                            <div v-show="canDraw">
                                <div
                                    class="mb-1 flex justify-between text-xs tracking-wider text-[#6bdc9f]/80"
                                >
                                    <span>{{ t('gacha.draws_limit') }}</span>
                                    <span>{{
                                        drawsPerUser === 0
                                            ? t('gacha.unlimited')
                                            : drawsPerUser
                                    }}</span>
                                </div>
                                <input
                                    type="range"
                                    :value="drawsPerUser"
                                    min="0"
                                    max="20"
                                    step="1"
                                    class="tune-slider w-full"
                                    @change="
                                        drawsPerUser = parseInt(
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        );
                                        sendMachineState();
                                    "
                                />
                            </div>
                        </div>

                        <button
                            v-show="drawsPerUser > 0"
                            class="mt-4 w-full rounded-xl border border-[#2f4739] bg-[#1d2a22] py-2.5 text-xs font-bold tracking-widest text-[#6bdc9f] transition-colors hover:bg-[#233328] disabled:opacity-40"
                            @click="resetAllDraws"
                        >
                            {{ t('gacha.reset_draws') }}
                        </button>
                    </section>

                    <div v-if="isHost" class="border-t border-white/5" />

                    <!-- Broadcast log -->
                    <section>
                        <div
                            class="mb-2 text-[10px] font-bold tracking-[0.35em] text-[#6bdc9f]/55"
                        >
                            {{ t('gacha.broadcast_label') }}
                        </div>
                        <div
                            v-if="broadcastLog.length === 0"
                            class="py-2 text-center text-xs tracking-widest text-[#6bdc9f]/30"
                        >
                            —
                        </div>
                        <div
                            class="flex max-h-32 flex-col gap-1 overflow-y-auto"
                        >
                            <div
                                v-for="(entry, i) in [
                                    ...broadcastLog,
                                ].reverse()"
                                :key="i"
                                class="flex items-baseline gap-2 font-mono text-[10px]"
                            >
                                <span class="shrink-0 text-[#6bdc9f]/25">{{
                                    new Date(entry.ts).toLocaleTimeString()
                                }}</span>
                                <span class="text-[#6bdc9f]/70">{{
                                    entry.text
                                }}</span>
                            </div>
                        </div>
                    </section>

                    <div class="border-t border-white/5" />

                    <!-- Draw history -->
                    <section>
                        <div
                            class="mb-2 text-[10px] font-bold tracking-[0.35em] text-[#6bdc9f]/55"
                        >
                            {{ t('gacha.draw_log_label') }}
                        </div>
                        <div
                            v-if="drawHistory.length === 0"
                            class="py-4 text-center text-xs tracking-widest text-[#6bdc9f]/30"
                        >
                            {{ t('gacha.waiting_draw') }}
                        </div>
                        <div
                            class="flex max-h-64 flex-col gap-2 overflow-y-auto"
                        >
                            <div
                                v-for="(event, i) in [...drawHistory].reverse()"
                                :key="i"
                                class="rounded-xl border border-white/5 bg-black/30 p-3 text-xs"
                            >
                                <div
                                    class="mb-1.5 flex justify-between text-[10px]"
                                >
                                    <span
                                        class="font-bold tracking-wider text-[#6bdc9f]/70"
                                        >{{ event.player }}</span
                                    >
                                    <span class="text-[#6bdc9f]/30">{{
                                        new Date(event.ts).toLocaleTimeString()
                                    }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="(r, j) in event.results"
                                        :key="j"
                                        class="rounded border px-1.5 py-0.5 text-[9px] font-bold"
                                        :style="{
                                            color: r.quality.color,
                                            borderColor: r.quality.color + '55',
                                        }"
                                        >{{
                                            r.quality.code.split('_')[0]
                                        }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </section>
                </template>

                <!-- STANDALONE -->
                <template v-else>
                    <section>
                        <div
                            class="mb-2 text-[10px] font-bold tracking-[0.35em] text-[#6bdc9f]/55"
                        >
                            {{ t('gacha.host_control') }}
                        </div>
                        <h3
                            class="mb-4 text-xl font-semibold tracking-tight text-white"
                        >
                            {{ t('gacha.machine_control') }}
                        </h3>

                        <div class="space-y-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-xs tracking-wider text-[#6bdc9f]/80"
                                    >{{ t('gacha.ten_sync_mode') }}</span
                                >
                                <button
                                    class="relative h-6 w-12 rounded-full transition-colors"
                                    :class="
                                        isTenPull
                                            ? 'bg-[#6bdc9f]'
                                            : 'bg-[#2f4739]'
                                    "
                                    @click="isTenPull = !isTenPull"
                                >
                                    <span
                                        class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                                        :class="
                                            isTenPull ? 'translate-x-6' : ''
                                        "
                                    />
                                </button>
                            </div>

                            <div class="flex items-center justify-between">
                                <span
                                    class="text-xs tracking-wider text-[#6bdc9f]/80"
                                    >{{ t('gacha.skip_animation') }}</span
                                >
                                <button
                                    class="relative h-6 w-12 rounded-full transition-colors"
                                    :class="
                                        skipAnim
                                            ? 'bg-[#6bdc9f]'
                                            : 'bg-[#2f4739]'
                                    "
                                    @click="skipAnim = !skipAnim"
                                >
                                    <span
                                        class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                                        :class="skipAnim ? 'translate-x-6' : ''"
                                    />
                                </button>
                            </div>

                            <div v-if="!isTenPull">
                                <div
                                    class="mb-1 text-xs tracking-wider text-[#6bdc9f]/80"
                                >
                                    {{ t('gacha.force_quality') }}
                                </div>
                                <QualitySelect v-model="selectedQuality" />
                            </div>

                            <div v-if="isTenPull">
                                <div
                                    class="mb-2 text-xs tracking-wider text-[#6bdc9f]/80"
                                >
                                    {{ t('gacha.ten_pull_qualities') }}
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <label
                                        v-for="(_, i) in tenPullQualities"
                                        :key="i"
                                        class="block text-[10px] tracking-wider text-[#6bdc9f]/75"
                                    >
                                        <div class="mb-1">
                                            {{
                                                t('gacha.slot_label', {
                                                    n: i + 1,
                                                })
                                            }}
                                        </div>
                                        <QualitySelect
                                            :model-value="tenPullQualities[i]"
                                            @update:model-value="
                                                tenPullQualities[i] = $event
                                            "
                                        />
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div
                        v-if="wsAvailable"
                        class="mt-auto border-t border-white/5 pt-4"
                    >
                        <p
                            class="mb-3 text-center text-[10px] tracking-widest text-[#6bdc9f]/30"
                        >
                            {{ t('gacha.ws_available') }}
                        </p>
                        <button
                            class="w-full rounded-xl border border-[#6bdc9f]/30 py-2 text-xs font-bold tracking-widest text-[#6bdc9f] transition-colors hover:bg-[#6bdc9f]/10"
                            @click="
                                mode = 'lobby';
                                fetchRooms();
                            "
                        >
                            {{ t('gacha.enter_lobby') }}
                        </button>
                    </div>
                </template>
            </aside>
        </div>

        <!-- Result Modal -->
        <Teleport to="body">
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
                @click.self="showModal = false"
            >
                <div
                    class="glass-panel w-full max-w-md rounded-3xl p-8 text-center shadow-2xl"
                >
                    <div
                        class="mb-2 text-[10px] font-bold tracking-[0.4em] text-[#6bdc9f]/50"
                    >
                        DECODING COMPLETE
                    </div>
                    <h3
                        class="mb-6 text-2xl font-medium tracking-tight text-white"
                    >
                        ENTITY_DATA_RECOVERED
                    </h3>
                    <div class="mb-8 grid grid-cols-2 gap-3">
                        <div
                            v-for="(result, i) in lastResults"
                            :key="i"
                            class="rounded-xl bg-black/40 p-4 text-left"
                            :class="
                                result.quality.name === 'legendary'
                                    ? 'border border-[#d4af3755]'
                                    : 'border border-white/5'
                            "
                        >
                            <div
                                class="mb-1 text-[8px] font-bold tracking-widest"
                                :class="{
                                    'gradient-text':
                                        result.quality.name === 'legendary',
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
                                class="text-sm font-bold"
                                :class="{
                                    'gradient-text':
                                        result.quality.name === 'legendary',
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
                        class="btn-gradient w-full rounded-xl py-4 text-xs font-bold tracking-widest text-[#0f1511] uppercase transition-all hover:brightness-110"
                        @click="showModal = false"
                    >
                        Acknowledge
                    </button>
                </div>
            </div>
        </Teleport>

        <!-- Create Room Modal -->
        <Teleport to="body">
            <div
                v-if="showCreateModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
                @click.self="showCreateModal = false"
            >
                <div
                    class="glass-panel w-full max-w-sm rounded-3xl p-8 shadow-2xl"
                >
                    <div
                        class="mb-2 text-[10px] font-bold tracking-[0.4em] text-[#6bdc9f]/50"
                    >
                        CREATE ROOM
                    </div>
                    <h3
                        class="mb-1 text-lg font-medium tracking-tight text-white"
                    >
                        {{ t('gacha.create_modal_title') }}
                    </h3>
                    <p class="mb-6 text-xs tracking-widest text-[#6bdc9f]/40">
                        {{ t('gacha.name_hint') }}
                    </p>
                    <input
                        v-model="createName"
                        type="text"
                        maxlength="30"
                        :placeholder="t('gacha.name_placeholder')"
                        class="mb-6 w-full border-b border-[#2f4739] bg-transparent pb-1 text-sm text-[#6bdc9f] transition-colors outline-none placeholder:text-[#6bdc9f]/30 focus:border-[#6bdc9f]"
                        @keyup.enter="submitCreateModal"
                    />
                    <button
                        :disabled="!createName.trim()"
                        class="btn-gradient w-full rounded-xl py-3 text-xs font-bold tracking-widest text-[#0f1511] uppercase transition-all hover:brightness-110 disabled:opacity-40"
                        @click="submitCreateModal"
                    >
                        {{ t('gacha.create_submit') }}
                    </button>
                </div>
            </div>
        </Teleport>

        <!-- Join Name Modal (unauthenticated) -->
        <Teleport to="body">
            <div
                v-if="joinTarget"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
                @click.self="joinTarget = null"
            >
                <div
                    class="glass-panel w-full max-w-sm rounded-3xl p-8 shadow-2xl"
                >
                    <div
                        class="mb-2 text-[10px] font-bold tracking-[0.4em] text-[#6bdc9f]/50"
                    >
                        JOIN ROOM
                    </div>
                    <h3
                        class="mb-1 text-lg font-medium tracking-tight text-white"
                    >
                        {{ joinTarget.room_name }}
                    </h3>
                    <p class="mb-6 text-xs tracking-widest text-[#6bdc9f]/40">
                        {{ t('gacha.name_hint') }}
                    </p>
                    <input
                        v-model="joinName"
                        type="text"
                        maxlength="30"
                        :placeholder="t('gacha.name_placeholder')"
                        class="mb-6 w-full border-b border-[#2f4739] bg-transparent pb-1 text-sm text-[#6bdc9f] transition-colors outline-none placeholder:text-[#6bdc9f]/30 focus:border-[#6bdc9f]"
                        @keyup.enter="submitJoinModal"
                    />
                    <p
                        v-if="joinError"
                        class="mb-4 text-xs tracking-wide text-red-400"
                    >
                        {{ joinError }}
                    </p>
                    <button
                        :disabled="!joinName.trim() || joinLoading"
                        class="btn-gradient w-full rounded-xl py-3 text-xs font-bold tracking-widest text-[#0f1511] uppercase transition-all hover:brightness-110 disabled:opacity-40"
                        @click="submitJoinModal"
                    >
                        {{ joinLoading ? t('gacha.joining') : t('gacha.join') }}
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import QualitySelect from '@/components/gacha/QualitySelect.vue';
import { useAuth } from '@/composables/useAuth';
import { useGachaPhysics } from '@/composables/useGachaPhysics';
import { useGachaRoom } from '@/composables/useGachaRoom';
import AppLayout from '@/layouts/AppLayout.vue';

const { t } = useI18n();

const { user } = useAuth();
const { chamberEl, runAnimation } = useGachaPhysics();
const {
    mode,
    wsAvailable,
    currentRoom,
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
    selectedQuality,
    tenPullQualities,
    drawHistory,
    broadcastLog,
    syncing,
    extractionDots,
    lastResults,
    showModal,
    statusText,
    drawsRemaining,
    canPressButton,
    sendMachineState,
    leaveRoom,
    resetAllDraws,
    startSync,
    fetchRooms,
} = useGachaRoom(user, runAnimation);
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
