<?php

namespace App\Models;

use Database\Factories\PreOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreOrder extends Model
{
    /** @use HasFactory<PreOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'status',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return HasMany<PreOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PreOrderItem::class);
    }
}
