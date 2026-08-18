<?php

namespace App\Events;

use App\Models\RestaurantTable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public RestaurantTable $table) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("restaurant.{$this->table->restaurant_id}.tables"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'table.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id'     => $this->table->id,
            'table_number' => $this->table->number,
            'status'       => $this->table->status,
        ];
    }
}