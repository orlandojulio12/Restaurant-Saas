<?php

namespace App\Events;

use App\Models\Ingredient;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockAlert implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Ingredient $ingredient) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("restaurant.{$this->ingredient->restaurant_id}.admin"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stock.low';
    }

    public function broadcastWith(): array
    {
        return [
            'ingredient_id' => $this->ingredient->id,
            'name'          => $this->ingredient->name,
            'current_stock' => $this->ingredient->stock,
            'min_stock'     => $this->ingredient->min_stock,
            'unit'          => $this->ingredient->unit,
        ];
    }
}