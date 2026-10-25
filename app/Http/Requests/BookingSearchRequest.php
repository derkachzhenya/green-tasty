<?php

namespace App\Http\Requests;

use App\Models\Restaurant;
use App\Services\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class BookingSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public availability contains no customer or reservation identifiers.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'restaurant' => ['nullable', 'string', Rule::exists('restaurants', 'slug')->where('is_active', true)],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now(BookingRules::TIMEZONE)->format('Y-m-d'), 'before_or_equal:'.CarbonImmutable::now(BookingRules::TIMEZONE)->addDays(BookingRules::ADVANCE_DAYS)->format('Y-m-d')],
            'time' => ['required', 'string', 'regex:/\A(?:all|(?:[01][0-9]|2[0-3]):(?:00|30))\z/'],
            'guest_count' => ['required', 'integer', 'between:1,'.BookingRules::MAX_GUESTS],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $field) {
                if (! in_array($field, [...array_keys($this->rules()), '_token'], true)) {
                    $validator->errors()->add($field, 'This field is not allowed.');
                }
            }
            if ($validator->errors()->isEmpty() && $this->input('time') !== 'all') {
                try {
                    app(BookingRules::class)->start($this->string('date')->toString(), $this->string('time')->toString());
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            }
        }];
    }

    public function restaurant(): ?Restaurant
    {
        return $this->filled('restaurant') ? Restaurant::query()->where('slug', $this->string('restaurant')->toString())->where('is_active', true)->firstOrFail() : null;
    }
}
