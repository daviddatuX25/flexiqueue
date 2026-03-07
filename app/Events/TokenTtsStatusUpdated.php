<?php

namespace App\Events;

use App\Models\Token;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to admin.token-tts when a token's TTS generation completes (per-token, so UI can update in real time).
 */
class TokenTtsStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Token $token
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.token-tts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'token_tts_status_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'token_id' => $this->token->id,
            'tts_status' => $this->token->tts_status,
            'tts_settings' => $this->token->tts_settings,
        ];
    }
}
