<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->load(['items.additionals', 'table', 'customer']);
    }

    public function broadcastOn(): array
    {
        // Una propuesta del comensal todavía no es trabajo para cocina: va al
        // mesero, que es quien la confirma al pasar por la mesa.
        $destino = $this->order->status === 'proposed' ? 'waiters' : 'kitchen';

        return [
            new PrivateChannel("restaurant.{$this->order->restaurant_id}.{$destino}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id'     => $this->order->id,
            'type'         => $this->order->type,
            'proposed'     => $this->order->status === 'proposed',
            'status'       => $this->order->status,
            'table_id'     => $this->order->table_id,
            'table_number' => $this->order->table?->number,
            'customer'     => $this->order->customer ? [
                'id'   => $this->order->customer->id,
                'name' => $this->order->customer->name,
            ] : null,
            'notes'        => $this->order->notes,
            'total'        => $this->order->total,
            'created_at'   => $this->order->created_at,
            'items'        => $this->order->items->map(fn($item) => [
                'id'           => $item->id,
                'product_name' => $item->product_name,
                'quantity'     => $item->quantity,
                'notes'        => $item->notes,
                'additionals'  => $item->additionals->map(fn($a) => [
                    'name'        => $a->additional_name,
                    'extra_price' => $a->extra_price,
                ]),
            ]),
        ];
    }
}