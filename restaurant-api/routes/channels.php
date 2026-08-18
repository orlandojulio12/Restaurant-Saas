<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Aquí se autoriza quién puede escuchar cada canal privado.
| El patrón restaurant.{restaurantId}.{scope} cubre todos los canales
| de un restaurante (kitchen, waiters, tables, admin).
|
*/

Broadcast::channel('restaurant.{restaurantId}.{scope}', function ($user, $restaurantId) {
    return (int) $user->restaurant_id === (int) $restaurantId;
});