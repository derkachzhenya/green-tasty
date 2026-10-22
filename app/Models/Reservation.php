<?php

namespace App\Models;

use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'restaurant_table_id',
        'owner_user_id',
        'code',
        'reserved_at',
        'guest_count',
        'status',
        'special_requests',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'guest_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<RestaurantTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(
            RestaurantTable::class,
            'restaurant_table_id'
        );
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'owner_user_id'
        );
    }

    /** @return HasMany<ReservationGuest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(ReservationGuest::class);
    }

    /** @return HasOne<PreOrder, $this> */
    public function preOrder(): HasOne
    {
        return $this->hasOne(PreOrder::class);
    }
}
