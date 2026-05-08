<?php

namespace App\Events\Gacha;

use App\Models\Gacha\GachaPlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public GachaPlayer $player,
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
            'player' => ['id' => $this->player->id, 'name' => $this->player->name, 'is_host' => $this->player->is_host],
        ];
    }
}
