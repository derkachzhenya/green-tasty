<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;

class MyReservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Reservation::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'status' => ['sometimes', 'in:all,upcoming,in_progress,completed,canceled']];
    }
}
