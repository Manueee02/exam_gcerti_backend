<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExamSessionEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $sessionPublicId,
        public readonly string $reason = 'closed_by_examiner'
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("exam-session.{$this->sessionPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'session.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'session_public_id' => $this->sessionPublicId,
            'reason'            => $this->reason,
        ];
    }
}
