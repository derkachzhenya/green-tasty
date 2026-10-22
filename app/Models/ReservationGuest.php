<?php

namespace App\Models;

use Database\Factories\ReservationGuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationGuest extends Model
{
    /** @use HasFactory<ReservationGuestFactory> */
    use HasFactory;

    // This column stores only SHA-256 digests, never raw invitation tokens.
    protected $hidden = ['invitation_token'];

    protected $fillable = [
        'reservation_id',
        'user_id',
        'name',
        'email',
        'phone',
        'status',
        'responded_at',
    ];

    /** @return array{responded_at: 'datetime', invitation_expires_at: 'immutable_datetime', invitation_revoked_at: 'immutable_datetime'} */
    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'invitation_expires_at' => 'immutable_datetime',
            'invitation_revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PreOrderItem, $this> */
    public function preOrderItems(): HasMany
    {
        return $this->hasMany(
            PreOrderItem::class,
            'reservation_guest_id'
        );
    }
}
