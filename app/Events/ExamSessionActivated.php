<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExamSessionActivated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $plannedExamPublicId,
        public readonly string $sessionPublicId,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("planned-exam.{$this->plannedExamPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'session.activated';
    }

    public function broadcastWith(): array
    {
        return [
            'planned_exam_public_id' => $this->plannedExamPublicId,
            'session_public_id'      => $this->sessionPublicId,
        ];
    }
}
