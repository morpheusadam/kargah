<?php

/**
 * Times real page renders in the *dev* environment (not the testing env the
 * suite uses), so we measure what the browser actually hits.
 * Delete this file once the cause is found.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::first();
if (! $user) {
    exit("no user in database\n");
}

foreach (['/login', '/dashboard', '/projects', '/mail/inbox', '/accounting/invoices'] as $uri) {
    $t0 = microtime(true);

    try {
        $request = Illuminate\Http\Request::create($uri, 'GET');
        $app['session']->driver()->start();
        $request->setLaravelSession($app['session']->driver());
        Illuminate\Support\Facades\Auth::setUser($user);

        $response = $kernel->handle($request);

        $ms = round((microtime(true) - $t0) * 1000);
        printf("%-22s %s  %6d ms  %7.1f KB\n", $uri, $response->getStatusCode(), $ms, strlen($response->getContent()) / 1024);
    } catch (Throwable $e) {
        $ms = round((microtime(true) - $t0) * 1000);
        printf("%-22s FAILED after %d ms: %s\n   at %s:%d\n", $uri, $ms, $e->getMessage(), $e->getFile(), $e->getLine());
    }
}
