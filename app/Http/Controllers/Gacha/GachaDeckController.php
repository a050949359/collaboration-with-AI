<?php

namespace App\Http\Controllers\Gacha;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gacha\StoreGachaDeckRequest;
use App\Http\Requests\Gacha\UpdateGachaDeckRequest;
use App\Models\Gacha\GachaDeck;
use Illuminate\Http\JsonResponse;

class GachaDeckController extends Controller
{
    // GET /api/v1/gacha/decks
    public function index(): JsonResponse
    {
        $decks = GachaDeck::with('cards:id,name,rarity,weight')->get(['id', 'name']);

        return response()->json($decks);
    }

    // POST /api/v1/gacha/decks  (admin)
    public function store(StoreGachaDeckRequest $request): JsonResponse
    {
        $deck = GachaDeck::create(['name' => $request->input('name')]);

        if ($request->filled('card_ids')) {
            $deck->cards()->attach($request->input('card_ids'));
        }

        return response()->json($deck->load('cards:id,name,rarity,weight'), 201);
    }

    // PUT /api/v1/gacha/decks/{deck}  (admin) — 更新名稱 + 卡牌
    public function update(UpdateGachaDeckRequest $request, GachaDeck $deck): JsonResponse
    {
        if ($request->has('name')) {
            $deck->update(['name' => $request->input('name')]);
        }

        if ($request->has('card_ids')) {
            $deck->cards()->sync($request->input('card_ids', []));
        }

        return response()->json($deck->load('cards:id,name,rarity,weight'));
    }

    // DELETE /api/v1/gacha/decks/{deck}  (admin)
    public function destroy(GachaDeck $deck): JsonResponse
    {
        $deck->delete();

        return response()->json(['ok' => true]);
    }
}
