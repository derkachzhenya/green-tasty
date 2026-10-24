<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $email
 * @property string $email_hash
 * @property string $purpose
 * @property string $generation
 * @property string|null $code_hash
 * @property string|null $password_fingerprint
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $resend_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $consumed_at
 */
class AuthChallenge extends Model
{
    protected $guarded = ['id'];

    // Challenges are never response DTOs, even when accidentally serialized.
    protected $hidden = ['user_id', 'email', 'email_hash', 'generation', 'code_hash', 'password_fingerprint'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'resend_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
