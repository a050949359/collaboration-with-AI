<?php

namespace App\Http\Controllers\Gacha;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gacha\StoreGachaCardRequest;
use App\Models\Gacha\GachaCard;
use Illuminate\Http\JsonResponse;

class GachaCardController extends Controller
{
    // GET /api/v1/gacha/cards
    public function index(): JsonResponse
    {
        return response()->json(
            GachaCard::orderBy('rarity')->orderBy('name')->get(['id', 'name', 'rarity', 'weight', 'image_url'])
        );
    }

    // POST /api/v1/gacha/cards  (admin)
    public function store(StoreGachaCardRequest $request): JsonResponse
    {
        $card = GachaCard::create($request->validated());

        return response()->json($card, 201);
    }

    // DELETE /api/v1/gacha/cards/{id}  (admin)
    public function destroy(GachaCard $card): JsonResponse
    {
        $card->delete();

        return response()->json(['ok' => true]);
    }
}
