<?php

namespace Tests\Feature\Auth;

use App\Models\AuthChallenge;
use App\Models\User;
use App\Notifications\AuthVerificationCode;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthCodeConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private ?Connection $heldConnection = null;

    private bool $databasePrepared = false;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires real MySQL row locks.');
        }
        $this->assertSame('testing', DB::connection()->getDatabaseName(), 'Use only the isolated MySQL testing database.');
    }

    protected function tearDown(): void
    {
        if ($this->heldConnection !== null) {
            while ($this->heldConnection->transactionLevel() > 0) {
                $this->heldConnection->rollBack();
            }
            DB::purge('auth_code_race');
        }
        if ($this->databasePrepared) {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    public function test_a_competing_verification_waits_for_consumption_then_rejects_the_reused_code(): void
    {
        $this->databasePrepared = true;
        Cache::flush();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertOk();
        $code = Notification::sent(new AnonymousNotifiable, AuthVerificationCode::class)->sole()->code;
        $context = session('auth.codes.registration');
        $body = ['purpose' => 'registration', 'code' => $code];
        $default = config('database.default');
        config(['database.connections.auth_code_race' => config('database.connections.'.$default)]);
        $this->heldConnection = DB::connection('auth_code_race');
        $this->heldConnection->beginTransaction();
        try {
            config(['database.default' => 'auth_code_race']);
            $this->postJson(route('auth.code.verify'), $body)->assertOk();
        } finally {
            config(['database.default' => $default]);
        }
        $connection = DB::connection();
        $timeout = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->withoutExceptionHandling();
        $failure = null;
        try {
            $this->withSession(['auth' => ['codes' => ['registration' => $context]]])->postJson(route('auth.code.verify'), $body);
        } catch (QueryException $exception) {
            $failure = $exception;
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
            $this->withExceptionHandling();
        }
        $this->assertInstanceOf(QueryException::class, $failure, 'Competing verification must wait for the challenge row lock.');
        $this->assertSame(1205, $failure->errorInfo[1]);
        $this->assertSame(0, $connection->transactionLevel());
        $this->heldConnection->commit();
        $this->withSession(['auth' => ['codes' => ['registration' => $context]]])->postJson(route('auth.code.verify'), $body)->assertUnprocessable();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(1, AuthChallenge::sole()->attempts);
        $this->assertNull(AuthChallenge::sole()->code_hash);
        $this->assertGuest();
    }

    public function test_challenge_creation_sees_a_competing_committed_row_after_an_older_mysql_snapshot(): void
    {
        $this->databasePrepared = true;
        Cache::flush();
        Notification::fake();
        $user = User::factory()->create();
        $default = config('database.default');
        config(['database.connections.auth_code_race' => config('database.connections.'.$default)]);
        $this->heldConnection = DB::connection('auth_code_race');
        $this->heldConnection->beginTransaction();
        DB::beginTransaction();
        try {
            $this->assertSame(0, AuthChallenge::count());
            AuthChallenge::on('auth_code_race')->create([
                'email_hash' => hash('sha256', $user->email), 'email' => $user->email, 'purpose' => 'recovery',
                'generation' => bin2hex(random_bytes(16)), 'expires_at' => now()->addMinutes(10), 'resend_at' => now()->addMinute(),
            ]);
            $this->heldConnection->commit();
            $this->postJson(route('password.email'), ['email' => $user->email])->assertTooManyRequests();
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('auth_challenges', 1);
        Notification::assertNothingSent();
    }
}
