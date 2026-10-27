<?php

namespace App\Models;

use App\Services\BookingRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ReservationFactory;
use Illuminate\Contracts\Database\Query\Expression as SqlExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Expression;

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
        'duration_minutes',
        'guest_count',
        'status',
        'special_requests',
    ];

    protected $attributes = ['duration_minutes' => BookingRules::DURATION_MINUTES];

    /** @return array{reserved_at: 'datetime', guest_count: 'integer', duration_minutes: 'integer'} */
    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'guest_count' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->reserved_at)->addMinutes($this->duration_minutes);
    }

    /** @param Builder<Reservation> $query
     * @return Builder<Reservation>
     */
    public function scopeEndingAfter(Builder $query, CarbonInterface $instant): Builder
    {
        return $query->where($this->endExpression(), '>', $instant->copy()->utc()->format('Y-m-d H:i:s'));
    }

    /** @param Builder<Reservation> $query
     * @return Builder<Reservation>
     */
    public function scopeEndedBy(Builder $query, CarbonInterface $instant): Builder
    {
        return $query->where($this->endExpression(), '<=', $instant->copy()->utc()->format('Y-m-d H:i:s'));
    }

    private function endExpression(): SqlExpression
    {
        return new Expression(match ($this->getConnection()->getDriverName()) {
            'sqlite' => 'datetime("reservations"."reserved_at", \'+\' || "reservations"."duration_minutes" || \' minutes\')',
            'pgsql' => '("reservations"."reserved_at" + "reservations"."duration_minutes" * interval \'1 minute\')',
            'sqlsrv' => 'DATEADD(minute, [reservations].[duration_minutes], [reservations].[reserved_at])',
            default => 'DATE_ADD(`reservations`.`reserved_at`, INTERVAL `reservations`.`duration_minutes` MINUTE)',
        });
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

    /** @return HasOne<ReservationFeedback, $this> */
    public function feedback(): HasOne
    {
        return $this->hasOne(ReservationFeedback::class);
    }

    /** @return HasOne<PreOrder, $this> */
    public function preOrder(): HasOne
    {
        return $this->hasOne(PreOrder::class);
    }
}
