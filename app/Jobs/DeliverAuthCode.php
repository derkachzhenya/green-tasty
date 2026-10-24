<?php

namespace App\Jobs;

use App\Models\AuthChallenge;
use App\Notifications\AuthVerificationCode;
use App\Support\AuthMailConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use SensitiveParameter;
use Throwable;

class DeliverAuthCode implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private int $challengeId, private string $generation, #[SensitiveParameter] private string $code)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $row = AuthChallenge::whereKey($this->challengeId)->where('generation', $this->generation)->first();
        if ($row === null || $row->user_id === null || $row->code_hash === null || $row->consumed_at !== null || $row->verified_at !== null || ! $row->expires_at->isFuture()) {
            return;
        }

        try {
            app(AuthMailConfiguration::class)->assertSafe();
            Notification::route('mail', $row->email)->notify(new AuthVerificationCode($this->code, $row->purpose));
        } catch (Throwable $exception) {
            // Never let transport exceptions containing email bodies reach worker logs.
            Log::warning('Authentication email delivery failed.', ['type' => $exception::class]);
            AuthChallenge::whereKey($row->id)->where('generation', $this->generation)->update(['code_hash' => null]);
        }
    }
}
