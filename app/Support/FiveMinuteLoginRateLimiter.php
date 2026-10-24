<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;

class FiveMinuteLoginRateLimiter extends LoginRateLimiter
{
    public function increment(Request $request): void
    {
        $key = $this->throttleKey($request);
        if ($this->limiter->hit($key, 300) >= 5) {
            // A separate bucket starts the full lockout at the fifth failure.
            $this->limiter->hit($key.':lock', 300);
        }
    }

    public function tooManyAttempts(Request $request): bool
    {
        return $this->limiter->tooManyAttempts($this->throttleKey($request).':lock', 1);
    }

    public function availableIn(Request $request): int
    {
        return $this->limiter->availableIn($this->throttleKey($request).':lock');
    }

    public function clear(Request $request): void
    {
        parent::clear($request);
        $this->limiter->clear($this->throttleKey($request).':lock');
    }

    public function throttleKey(Request $request): string
    {
        $cached = $request->attributes->get('auth.login_throttle_key');
        if (is_string($cached)) {
            return $cached;
        }
        $email = mb_strtolower((string) $request->input('email'));
        // Use the same database identity as Fortify: MySQL may equate accented aliases.
        $id = User::where('email', $email)->value('id');
        $subject = $id === null ? 'email:'.$email : 'user:'.(string) $id;
        $key = 'login:'.hash('sha256', $subject.'|'.$request->ip());
        $request->attributes->set('auth.login_throttle_key', $key);

        return $key;
    }
}
