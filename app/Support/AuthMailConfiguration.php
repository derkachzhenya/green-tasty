<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AuthMailConfiguration
{
    public function assertSafe(): void
    {
        $name = (string) config('mail.default');
        $safe = function (string $name, array $seen = []) use (&$safe): bool {
            if (in_array($name, $seen, true)) {
                return false;
            }
            $configuration = config('mail.driver') ? config('mail') : config('mail.mailers.'.$name);
            if (! is_array($configuration)) {
                return false;
            }
            $transport = $configuration['transport'] ?? config('mail.driver');
            // Laravel's MAIL_URL overrides transport, including nested mailers.
            if (isset($configuration['url'])) {
                if (! is_string($configuration['url'])) {
                    return false;
                }
                try {
                    $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
                } catch (InvalidArgumentException) {
                    return false;
                }
                $transport = $configuration['driver'] ?? null;
            }
            if ($transport === 'log' || $transport === null || ($transport === 'array' && ! app()->environment(['testing', 'local']))) {
                return false;
            }
            foreach ((array) ($configuration['mailers'] ?? []) as $child) {
                if (! is_string($child) || ! $safe($child, [...$seen, $name])) {
                    return false;
                }
            }

            return true;
        };
        if (! $safe($name)) {
            throw ValidationException::withMessages(['email' => 'Verification email delivery is not configured. Please contact the site administrator.']);
        }
    }
}
