<?php

namespace App\Http\Controllers\Gacha;

use App\Http\Controllers\Controller;
use App\Models\Gacha\GachaDraw;
use App\Models\Gacha\GachaPlayer;
use App\Models\Gacha\GachaRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GachaRoomController extends Controller
{
    private string $mgmtAddr = '127.0.0.1:9002';

    // GET /api/v1/gacha/rooms
    public function index(): JsonResponse
    {
        $rooms = GachaRoom::where('status', '!=', 'finished')
            ->withCount('players')
            ->get(['id', 'code', 'room_name', 'status', 'max_players']);

        try {
            $res = Http::timeout(2)->get("http://{$this->mgmtAddr}/rooms");
            if ($res->ok()) {
                $goRoomIds = collect($res->json())->pluck('id')->flip();
                $rooms = $rooms->filter(fn($r) => isset($goRoomIds[$r->code]))->values();
            }
        } catch (\Throwable) {
            // Go server unreachable, return DB rooms as-is
        }

        return response()->json($rooms);
    }

    // POST /api/v1/gacha/rooms
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'room_name'   => 'nullable|string|max:50',
            'player_name' => 'nullable|string|max:30',
            'deck_id'     => 'nullable|integer|exists:gacha_decks,id',
        ]);

        $code       = strtoupper(Str::random(6));
        $playerName = $request->player_name ?? auth()->user()->name;

        $room = GachaRoom::create([
            'code'      => $code,
            'room_name' => $request->room_name ?? ($playerName . "'s Room"),
            'owner_id'  => auth()->id(),
            'deck_id'   => $request->input('deck_id'),
            'type'      => 'user',
        ]);

        $player = GachaPlayer::create([
            'room_id' => $room->id,
            'name'    => $playerName,
            'is_host' => true,
        ]);

        try {
            Http::timeout(3)->post("http://{$this->mgmtAddr}/rooms", [
                'id'        => $code,
                'type'      => 'gacha',
                'host_name' => auth()->user()->name,
            ]);
        } catch (\Throwable) {
            // ws server not running, room still created in DB
        }

        return response()->json(['room' => $room, 'player_id' => $player->id], 201);
    }

    // DELETE /api/v1/gacha/rooms/{code}
    public function destroy(string $code): JsonResponse
    {
        $room = GachaRoom::where('code', $code)->firstOrFail();

        if ($room->owner_id !== auth()->id()) {
            abort(403);
        }

        $room->update(['status' => 'finished']);

        return response()->json(['ok' => true]);
    }

    // POST /api/v1/gacha/rooms/{code}/join
    public function join(Request $request, string $code): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:30']);

        $room = GachaRoom::where('code', $code)
            ->where('status', '!=', 'finished')
            ->firstOrFail();

        if ($room->players()->count() >= $room->max_players) {
            return response()->json(['message' => 'room full'], 422);
        }

        $player = GachaPlayer::firstOrCreate(
            ['room_id' => $room->id, 'name' => $request->name],
            ['is_host' => false],
        );

        try {
            Http::timeout(3)->post("http://{$this->mgmtAddr}/rooms", [
                'id'        => $code,
                'type'      => 'gacha',
                'host_name' => $room->players()->where('is_host', true)->value('name') ?? '',
            ]);
        } catch (\Throwable) {
            // ws server not running
        }

        return response()->json(['player_id' => $player->id, 'room' => $room]);
    }

    // POST /api/v1/gacha/rooms/{code}/draw
    public function draw(Request $request, string $code): JsonResponse
    {
        $request->validate([
            'player_id'      => 'required|integer',
            'is_ten_pull'    => 'boolean',
            'can_draw'       => 'boolean',
            'draws_per_user' => 'integer|min:0',
        ]);

        if (!$request->boolean('can_draw', true)) {
            return response()->json(['message' => 'draws not open'], 403);
        }

        $room = GachaRoom::with('deck.cards')
            ->where('code', $code)
            ->where('status', '!=', 'finished')
            ->firstOrFail();

        $player = GachaPlayer::where('id', $request->player_id)
            ->where('room_id', $room->id)
            ->firstOrFail();

        if (!$player->hasDrawsRemaining($request->integer('draws_per_user', 0))) {
            return response()->json(['message' => 'draws exhausted'], 403);
        }

        $count   = $request->boolean('is_ten_pull') ? 10 : 1;
        $results = $this->generateResults($count, $room);

        foreach ($results as $result) {
            GachaDraw::create([
                'room_id'   => $room->id,
                'player_id' => $player->id,
                'card_id'   => $result['card']['id'] ?? null,
                'result'    => $result,
            ]);
        }

        $player->increment('draws_used', $count);

        try {
            Http::timeout(3)->post("http://{$this->mgmtAddr}/rooms/{$code}/broadcast", [
                'type'    => 'draw_result',
                'player'  => $player->name,
                'results' => $results,
                'ts'      => now()->toIso8601String(),
            ]);
        } catch (\Throwable) {
            // ws server not running, draw still recorded
        }

        return response()->json(['results' => $results]);
    }

    // POST /api/v1/gacha/rooms/{code}/reset-draws
    public function resetDraws(string $code): JsonResponse
    {
        $room = GachaRoom::where('code', $code)->firstOrFail();

        if ($room->owner_id !== auth()->id()) {
            abort(403);
        }

        $room->players()->update(['draws_used' => 0]);

        try {
            Http::timeout(3)->post("http://{$this->mgmtAddr}/rooms/{$code}/broadcast", [
                'type' => 'draws_reset',
            ]);
        } catch (\Throwable) {}

        return response()->json(['ok' => true]);
    }

    private function generateResults(int $count, GachaRoom $room): array
    {
        $cards = $room->deck?->cards ?? collect();

        if ($cards->isNotEmpty()) {
            return $this->drawFromCards($count, $cards);
        }

        return $this->drawFromFallback($count);
    }

    private function drawFromCards(int $count, \Illuminate\Support\Collection $cards): array
    {
        $totalWeight = $cards->sum('weight');
        $results     = [];

        for ($i = 0; $i < $count; $i++) {
            $roll       = random_int(1, max(1, $totalWeight));
            $cumulative = 0;
            $selected   = $cards->first();
            foreach ($cards as $card) {
                $cumulative += $card->weight;
                if ($roll <= $cumulative) {
                    $selected = $card;
                    break;
                }
            }

            $results[] = [
                'quality' => [
                    'name' => $selected->rarity,
                    'code' => strtoupper($selected->rarity) . '_ENTITY',
                ],
                'code' => 'V-SYNC_' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                'card' => [
                    'id'        => $selected->id,
                    'name'      => $selected->name,
                    'image_url' => $selected->image_url,
                ],
            ];
        }

        return $results;
    }

    private function drawFromFallback(int $count): array
    {
        $tiers = [
            ['name' => 'common',    'code' => 'COMMON_ENTITY',    'weight' => 60],
            ['name' => 'rare',      'code' => 'RARE_ENTITY',      'weight' => 25],
            ['name' => 'epic',      'code' => 'EPIC_ENTITY',      'weight' => 12],
            ['name' => 'legendary', 'code' => 'LEGENDARY_ENTITY', 'weight' => 3],
        ];

        $totalWeight = array_sum(array_column($tiers, 'weight'));
        $results     = [];

        for ($i = 0; $i < $count; $i++) {
            $roll       = random_int(1, $totalWeight);
            $cumulative = 0;
            $selected   = $tiers[0];
            foreach ($tiers as $tier) {
                $cumulative += $tier['weight'];
                if ($roll <= $cumulative) {
                    $selected = $tier;
                    break;
                }
            }
            $results[] = [
                'quality' => [
                    'name' => $selected['name'],
                    'code' => $selected['code'],
                ],
                'code' => 'V-SYNC_' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            ];
        }

        return $results;
    }
}
