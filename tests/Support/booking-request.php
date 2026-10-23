<?php

// A separate application process exercises the real HTTP middleware/controller path on MySQL.
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'testing') {
    throw new RuntimeException('Booking worker is restricted to the isolated MySQL testing database.');
}

CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 08:00:00', 'UTC'));
$request = Request::create('/restaurants/'.$argv[2].'/book', 'POST', json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR), server: ['HTTP_ACCEPT' => 'application/json']);
$app->instance('request', $request);
Auth::guard()->setUser(User::query()->findOrFail($argv[1]));
DB::connection()->beforeExecuting(function (string $query): void {
    if (str_contains($query, 'from `restaurant_tables`') && str_contains($query, 'for update')) {
        fwrite(STDOUT, "attempting-table-lock\n");
        fflush(STDOUT);
    }
});
$response = $kernel->handle($request);
fwrite(STDOUT, 'response-status:'.$response->getStatusCode()."\n");
if ($response->getStatusCode() !== 422) {
    fwrite(STDERR, $response->getContent()."\n");
}
$kernel->terminate($request, $response);
