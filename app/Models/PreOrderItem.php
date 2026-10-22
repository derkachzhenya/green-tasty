<?php

namespace App\Models;

use Database\Factories\PreOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreOrderItem extends Model
{
    /** @use HasFactory<PreOrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'pre_order_id',
        'reservation_guest_id',
        'menu_item_id',
        'quantity',
        'unit_price',
        'notes',
    ];

    /** @return array{quantity: 'integer', unit_price: 'decimal:2'} */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PreOrder, $this> */
    public function preOrder(): BelongsTo
    {
        return $this->belongsTo(PreOrder::class);
    }

    /** @return BelongsTo<ReservationGuest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(
            ReservationGuest::class,
            'reservation_guest_id'
        );
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
