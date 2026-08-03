<?php

/**
 * Times real page renders against the *dev* database, not the in-memory one the
 * suite uses, so the number is what a browser would actually wait for.
 *
 * The budget is 200 ms warm per page (project-guaid/spec/05-build-order.md).
 * The first hit of each URL is discarded: it pays for autoloading, the route
 * cache and OPcache filling up, and nobody sees that number twice.
 *
 *     php timing-probe.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Bind a request before bootstrapping: the URL generator is constructed during
// boot and will not accept a null one.
$app->instance('request', Illuminate\Http\Request::create('/', 'GET'));
$kernel->bootstrap();

$urls = [
    '/dashboard',
    '/projects',
    '/projects/archive',
    '/projects/client-work/settings',
    '/mail/inbox',
    '/accounting/invoices',
    '/accounting/clients/1',
];

/** Render one URL as the first user and give back milliseconds plus the size. */
$render = function (string $uri) use ($app, $kernel): array {
    $request = Illuminate\Http\Request::create($uri, 'GET');

    // Bind before anything resolves the URL generator, which needs a request.
    $app->instance('request', $request);

    $user = App\Models\User::query()->first();

    if ($user !== null) {
        // Put the user in the session the way a real login does, so the auth
        // middleware lets the request through instead of redirecting.
        $session = $app['session']->driver();
        $session->start();
        $session->put('login_web_'.sha1(Illuminate\Auth\SessionGuard::class), $user->getAuthIdentifier());
        $request->setLaravelSession($session);
        $request->cookies->set($session->getName(), $app['encrypter']->encrypt($session->getId(), false));
    }

    $started = microtime(true);
    $response = $kernel->handle($request);
    $ms = (microtime(true) - $started) * 1000;

    return [$response->getStatusCode(), $ms, strlen($response->getContent()) / 1024];
};

printf("%-34s %-6s %9s %10s\n", 'URL', 'status', 'warm ms', 'KB');
printf("%s\n", str_repeat('-', 62));

$over = [];
$failed = [];

foreach ($urls as $uri) {
    try {
        $render($uri);            // cold, discarded
        [$status, $ms, $kb] = $render($uri);

        printf("%-34s %-6s %8.0f %9.1f%s\n", $uri, $status, $ms, $kb, $ms > 200 ? '  <- over budget' : '');

        if ($status >= 400 || $status === 302) {
            $failed[] = $uri.' ('.$status.')';
        } elseif ($ms > 200) {
            $over[] = $uri;
        }
    } catch (Throwable $e) {
        printf("%-34s FAILED  %s\n   at %s:%d\n", $uri, $e->getMessage(), $e->getFile(), $e->getLine());
        $failed[] = $uri;
    }
}

echo PHP_EOL;

if ($failed !== []) {
    echo 'Did not render: '.implode(', ', $failed)."\n";
}

echo $over === []
    ? "Every page that rendered is inside the 200 ms budget.\n"
    : 'Over budget: '.implode(', ', $over)."\n";
