<?php

namespace App\Http\Requests;

use App\Models\Restaurant;
use App\Services\BookingRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WaiterReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant && ($this->user()?->can('staff', $restaurant) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'staff.reservations.index' => ['date' => ['nullable', 'date_format:Y-m-d'], 'time' => ['nullable', 'date_format:H:i'], 'table' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'between:1,10000']],
            'staff.reservations.create', 'staff.reservations.update' => ['owner_email' => [$this->isMethod('post') ? 'required' : 'prohibited', 'email'], 'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'guest_count' => ['required', 'integer', 'between:1,255'], 'table_id' => ['required', 'integer', 'min:1'], 'duration_minutes' => ['sometimes', 'integer', Rule::in(BookingRules::DURATIONS)]],
            'staff.review.decision' => ['decision' => ['required', 'in:confirmed,rejected']],
            default => [],
        };
    }

    public function version(): string
    {
        $version = $this->header('If-Match');
        if (! is_string($version) || preg_match('/\A"[a-f0-9]{64}"\z/', $version) !== 1) {
            throw ValidationException::withMessages(['version' => 'A current If-Match version is required.']);
        }

        return $version;
    }
}
