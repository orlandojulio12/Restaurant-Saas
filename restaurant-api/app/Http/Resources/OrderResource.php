<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'restaurant_id'    => $this->restaurant_id,
            'type'             => $this->type,
            'status'           => $this->status,
            'delivery_address' => $this->delivery_address,
            'delivery_notes'   => $this->delivery_notes,
            'subtotal'         => $this->subtotal,
            'tax_amount'       => $this->tax_amount,
            'discount_amount'  => $this->discount_amount,
            'total'            => $this->total,
            'notes'            => $this->notes,
            'confirmed_at'     => $this->confirmed_at,
            'preparing_at'     => $this->preparing_at,
            'ready_at'         => $this->ready_at,
            'delivered_at'     => $this->delivered_at,
            'closed_at'        => $this->closed_at,
            'created_at'       => $this->created_at,
            'table'            => $this->whenLoaded('table', fn() => $this->table ? [
                'id'     => $this->table->id,
                'number' => $this->table->number,
                'zone'   => $this->table->relationLoaded('zone') ? $this->table->zone?->name : null,
            ] : null),
            'customer'         => $this->whenLoaded('customer', fn() => $this->customer ? [
                'id'    => $this->customer->id,
                'name'  => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'user'             => $this->whenLoaded('user', fn() => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role,
            ] : null),
            'items'   => OrderItemResource::collection($this->whenLoaded('items')),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id'     => $this->payment->id,
                'method' => $this->payment->method,
                'amount' => $this->payment->amount,
                'status' => $this->payment->status,
            ] : null),
        ];
    }
}