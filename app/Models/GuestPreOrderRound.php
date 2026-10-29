<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{menu_item_id: int, name: string, quantity: int, unit_price: string}> $items
 */
class GuestPreOrderRound extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'number' => 'integer', 'submitted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }
}
