<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationFeedback extends Model
{
    protected $table = 'reservation_feedback';

    protected $fillable = ['restaurant_rating', 'service_rating', 'comment'];

    protected function casts(): array
    {
        return ['restaurant_rating' => 'integer', 'service_rating' => 'integer'];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
