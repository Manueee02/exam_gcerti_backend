<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExamRunsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $sessionPublicId,
        public readonly array  $runs
    ) {}

    public function broadcastOn(): Channel
    {
        // Canale pubblico nominato per sessione — in Fase 4
        // puoi renderlo privato con PrivateChannel e autenticare
        // l'examiner, per ora Channel è sufficiente in locale
        return new Channel("exam-session.{$this->sessionPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'runs.updated';
    }
}
