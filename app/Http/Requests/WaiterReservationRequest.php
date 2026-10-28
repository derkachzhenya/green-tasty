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
            'staff.order.save' => ['items' => ['required', 'array', 'min:1', 'max:500'], 'items.*' => ['required', 'array:line_key,menu_item_id,quantity,notes'],
                'items.*.line_key' => ['required', 'string', 'regex:/\A(?:preorder|new)-[1-9][0-9]*\z/', 'distinct'],
                'items.*.menu_item_id' => ['required', 'integer', 'min:1'], 'items.*.quantity' => ['required', 'integer', 'between:1,99'], 'items.*.notes' => ['nullable', 'string', 'max:500']],
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
