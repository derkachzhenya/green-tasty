<?php

// A separate HTTP application process tests committed reads after a real MySQL lock wait.
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'testing') {
    throw new RuntimeException('Submission worker is restricted to the isolated MySQL testing database.');
}

$request = Request::create('/reservations/'.$argv[2].'/pre-order/submit', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
$app->instance('request', $request);
Auth::guard()->setUser(User::query()->findOrFail($argv[1]));
DB::connection()->beforeExecuting(function (string $query): void {
    if (str_contains($query, 'from `reservations`') && str_contains($query, 'for update')) {
        fwrite(STDOUT, "attempting-reservation-lock\n");
        fflush(STDOUT);
    }
});
$response = $kernel->handle($request);
fwrite(STDOUT, 'response-status:'.$response->getStatusCode()."\n");
if ($response->getStatusCode() === 200) {
    $summary = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    fwrite(STDOUT, 'grand-total:'.$summary['grand_total']."\n");
}
$kernel->terminate($request, $response);
