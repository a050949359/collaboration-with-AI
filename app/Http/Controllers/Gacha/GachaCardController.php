<?php

namespace App\Http\Controllers\Gacha;

use App\Http\Controllers\Controller;
use App\Models\Gacha\GachaCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => 'required|string|max:50',
            'rarity' => ['required', Rule::in(['common', 'rare', 'epic', 'legendary'])],
            'weight' => 'required|integer|min:1|max:9999',
        ]);

        $card = GachaCard::create($data);

        return response()->json($card, 201);
    }

    // DELETE /api/v1/gacha/cards/{id}  (admin)
    public function destroy(GachaCard $card): JsonResponse
    {
        $card->delete();

        return response()->json(['ok' => true]);
    }
}
