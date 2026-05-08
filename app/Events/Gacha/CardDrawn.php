<?php

namespace App\Events\Gacha;

use App\Models\Gacha\GachaCard;
use App\Models\Gacha\GachaPlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CardDrawn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public GachaPlayer $player,
        public GachaCard $card,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("gacha.room.{$this->roomId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'player' => ['id' => $this->player->id, 'name' => $this->player->name],
            'card'   => ['id' => $this->card->id, 'name' => $this->card->name, 'rarity' => $this->card->rarity, 'image_url' => $this->card->image_url],
        ];
    }
}
