<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class SetupStaffAccount extends Command
{
    protected $signature = 'staff:setup {email : Staff account email} {--restaurant= : Existing restaurant slug} {--create : Create a separate verified staff account if missing} {--name=Alex Caper : Name for a new account} {--apply : Apply changes; without this flag only preview}';

    protected $description = 'Prepare restaurant-scoped staff access without changing an existing password or customer bookings';

    public function handle(): int
    {
        $email = $this->argument('email');
        $slug = $this->option('restaurant');
        $name = $this->option('name');
        if (! is_string($email) || ! is_string($slug) || ! is_string($name) || Validator::make(['email' => $email, 'slug' => $slug, 'name' => $name], ['email' => 'required|email|max:255', 'slug' => 'required|string|max:255', 'name' => 'required|string|max:255'])->fails()) {
            $this->error('Provide a valid email, --restaurant=existing-slug and a nonempty --name.');

            return self::FAILURE;
        }
        $email = Str::lower($email);
        $restaurant = Restaurant::where('slug', $slug)->where('is_active', true)->first();
        if ($restaurant === null) {
            $this->error('An active restaurant with this slug is required. Install the restaurant menu first if this is a fresh application.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->first();
        if ($user === null && ! $this->option('create')) {
            $this->error('Account not found. Add --create to provision a separate staff account.');

            return self::FAILURE;
        }
        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $this->error('The existing account must verify its email before it can be used for staff sign-in.');

            return self::FAILURE;
        }
        $this->line('Restaurant: '.$restaurant->name.' ('.$restaurant->slug.')');
        $this->line('Staff email: '.$email.'; role: waiter.');
        if (! $this->option('apply')) {
            $this->info('Preview only. No account or role changed. Add --apply to provision this access.');

            return self::SUCCESS;
        }
        try {
            $password = DB::transaction(function () use ($email, $name, $restaurant): ?string {
                $lockedRestaurant = Restaurant::whereKey($restaurant->id)->lockForUpdate()->firstOrFail();
                if (! $lockedRestaurant->is_active) {
                    throw new RuntimeException('The restaurant is no longer active.');
                }
                $account = User::where('email', $email)->lockForUpdate()->first();
                $password = null;
                if ($account === null) {
                    if (! $this->option('create')) {
                        throw new RuntimeException('Account no longer exists.');
                    }
                    $password = Str::password(24);
                    $account = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
                    $account->forceFill(['email_verified_at' => now()])->save();
                }
                if (! $account->hasVerifiedEmail()) {
                    throw new RuntimeException('The account must verify its email.');
                }
                $membership = $account->staffRestaurants()->whereKey($lockedRestaurant->id)->first();
                if ($membership !== null && $membership->pivot->getAttribute('role') !== 'waiter') {
                    throw new RuntimeException('An existing different staff role will not be replaced.');
                }
                $account->staffRestaurants()->syncWithoutDetaching([$lockedRestaurant->id => ['role' => 'waiter', 'is_active' => true]]);

                return $password;
            }, attempts: 3);
            if ($password !== null) {
                $this->warn('New staff password (shown once; share privately): '.$password);
            } else {
                $this->line('The existing password was retained.');
            }
            $this->info('Staff access is ready. Open '.url('/staff/login').' and sign in with this staff account.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
