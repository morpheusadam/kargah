{{--
    The page a person sees after one click on the unsubscribe link.

    Deliberately standalone rather than rendered into the application shell.
    Everyone who reaches it is a stranger — no session, no sidebar to show them,
    and nothing here should depend on a layout that assumes an authenticated
    user. It is also the one page in Kargah a spam filter may fetch on the
    recipient's behalf, so it loads no scripts at all.

    The unsubscribe has already happened by the time this renders. There is no
    confirm button on purpose: `List-Unsubscribe-Post` promises the mail client
    that one request is enough, and a page that asks again fails the check the
    client makes.
--}}
<!DOCTYPE html>
<html class="h-full dark" data-kt-theme="true" data-kt-theme-mode="dark" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <title>Unsubscribed — Kargah</title>
    <link href="/assets/vendors/keenicons/styles.bundle.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
    <link href="/assets/css/kargah.css" rel="stylesheet">
</head>
<body class="antialiased h-full text-base text-foreground bg-background">

<div class="flex min-h-screen items-center justify-center p-5">
    <div class="kt-card w-full max-w-md">
        <div class="kt-card-content p-8 flex flex-col items-center text-center gap-4">

            @if ($email)
                <span class="inline-flex items-center justify-center size-14 rounded-full bg-success/10 text-success">
                    <i class="ki-filled ki-check-circle text-2xl"></i>
                </span>
                <h1 class="text-xl font-semibold text-mono">You have been unsubscribed</h1>
                <p class="text-sm text-secondary-foreground">
                    {{ $email }} will not receive
                    @if ($campaign)
                        any more messages from {{ $campaign }}, or anything else sent from here.
                    @else
                        any further messages sent from here.
                    @endif
                </p>
                <p class="text-xs text-muted-foreground">
                    The address is blocked across every delivery provider, so nothing can reach it by another route.
                    Nothing further is needed from you.
                </p>
            @else
                <span class="inline-flex items-center justify-center size-14 rounded-full bg-muted text-muted-foreground">
                    <i class="ki-filled ki-information-2 text-2xl"></i>
                </span>
                <h1 class="text-xl font-semibold text-mono">This link is no longer valid</h1>
                <p class="text-sm text-secondary-foreground">
                    It may have been used already, or it may belong to a campaign that has since been removed.
                </p>
                <p class="text-xs text-muted-foreground">
                    If you are still receiving messages, reply to the one you received and the address will be
                    blocked by hand.
                </p>
            @endif

        </div>
    </div>
</div>

</body>
</html>
