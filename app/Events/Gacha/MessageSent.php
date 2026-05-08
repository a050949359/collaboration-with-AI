<?php

namespace App\Events\Gacha;

use App\Models\Gacha\GachaMessage;
use App\Models\Gacha\GachaPlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public GachaPlayer $player,
        public GachaMessage $message,
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
            'player'  => ['id' => $this->player->id, 'name' => $this->player->name],
            'message' => ['id' => $this->message->id, 'body' => $this->message->message, 'created_at' => $this->message->created_at],
        ];
    }
}
