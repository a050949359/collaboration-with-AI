<?php

namespace Database\Seeders;

use App\Models\Gacha\GachaCard;
use App\Models\Gacha\GachaRoom;
use Illuminate\Database\Seeder;

class GachaTestSeeder extends Seeder
{
    public function run(): void
    {
        $room = GachaRoom::firstOrCreate(
            ['code' => 'TEST01'],
            [
                'room_name'      => '測試機台',
                'type'           => 'admin',
                'status'         => 'waiting',
                'max_players'    => 10,
                'min_level'      => 1,
                'draws_per_user' => 5,
                'can_draw'       => true,
                'skip_anim'      => false,
                'is_ten_pull'    => false,
            ]
        );

        $cards = [
            ['name' => 'ALPHA-01', 'rarity' => 'common',    'weight' => 50],
            ['name' => 'BETA-02',  'rarity' => 'common',    'weight' => 25],
            ['name' => 'GAMMA-03', 'rarity' => 'rare',      'weight' => 15],
            ['name' => 'DELTA-04', 'rarity' => 'epic',      'weight' => 8],
            ['name' => 'OMEGA-00', 'rarity' => 'legendary', 'weight' => 2],
        ];

        foreach ($cards as $data) {
            $card = GachaCard::firstOrCreate(
                ['name' => $data['name']],
                ['rarity' => $data['rarity'], 'weight' => $data['weight']]
            );
            $room->cards()->syncWithoutDetaching([$card->id]);
        }
    }
}
