<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;

class InvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation
            && ($this->user()?->can('manageInvitations', $reservation) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays(30)->toIso8601String()],
        ];
    }
}
