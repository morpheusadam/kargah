<?php

namespace Modules\Site\Services;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * One request to the website did not do what it was asked.
 *
 * A single exception rather than a hierarchy, because every caller does the
 * same thing with it: put `getMessage()` in front of the person and stop. What
 * varies is how much the message can say, and that is what the named
 * constructors are for.
 *
 * ## Why WordPress's own `code` is kept
 *
 * `rest_post_invalid_id`, `rest_cannot_edit`, `rest_forbidden` and friends are
 * stable strings WordPress has shipped for years, and they are the difference
 * between "the site said no" and "the application password belongs to a user
 * who cannot edit other people's posts". A caller that wants to branch — the
 * connection check does, to tell a wrong password from a missing capability —
 * reads {@see self::$errorCode}; a caller that only renders reads the message.
 *
 * It is `$errorCode` and not `$code` because `Exception` already owns `$code`,
 * declares it untyped, and PHP refuses a subclass that gives an inherited
 * property a type — a fatal at class-load time rather than a test failure.
 * WordPress's code is a string in any case, and `Exception::$code` is an int by
 * every convention that reads it.
 *
 * ## Why a 4xx is an exception here and a recorded failure in Social
 *
 * `HttpPublisher` turns a refusal into `PublishFailed` and lets the other three
 * targets go out, because a post to four networks must not die on one. Nothing
 * in this module is a fan-out: a page load asks the site for one list and has
 * nothing to show if it does not arrive. Unwinding to the component, which
 * draws an error state, is the honest shape.
 */
class SiteRequestFailed extends RuntimeException
{
    /**
     * WordPress's own machine-readable error code, when it sent one.
     *
     * Null for a transport failure, and for a response whose body was not the
     * `{code, message, data}` envelope WordPress errors use — a proxy's HTML
     * 502 page, most often, which is exactly the case that must not be reported
     * as though the site had an opinion about it.
     */
    public ?string $errorCode = null;

    /** The HTTP status, or null when the request never got one. */
    public ?int $status = null;

    public static function unreachable(string $url, string $detail): self
    {
        $failure = new self('Kargah could not reach '.$url.': '.$detail);

        return $failure;
    }

    /**
     * A response the site sent and Kargah will not act on.
     *
     * The message leads with WordPress's own wording when there is one. Its
     * copy is written for the person who owns the site and is usually better
     * than anything invented here — `Sorry, you are not allowed to edit this
     * post.` says more than `403 Forbidden` ever will.
     */
    public static function refused(Response $response, string $url): self
    {
        $body = $response->json();

        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : null;
        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : null;

        $failure = new self(
            $message !== null
                ? rtrim($message, '.').' ('.$response->status().')'
                : 'The site refused the request with '.$response->status().' and no explanation, at '.$url,
        );

        $failure->errorCode = $code;
        $failure->status = $response->status();

        return $failure;
    }

    /**
     * A 200 whose body was not JSON.
     *
     * Worth its own constructor because on a WordPress install this almost
     * always means one specific thing: a plugin or theme printed a notice
     * before the REST response, so the body is valid JSON with a PHP warning
     * glued to the front of it. Saying that is more useful than "malformed".
     */
    public static function malformed(string $url): self
    {
        $failure = new self(
            'The site answered '.$url.' with something that is not JSON. '
            .'A plugin or theme printing output before the REST response will do this.',
        );

        $failure->status = 200;

        return $failure;
    }

    /** Nothing is connected, so there is no site to ask. */
    public static function notConnected(): self
    {
        $failure = new self(
            'No WordPress site is connected. Connect one under Social → Accounts before using these pages.',
        );

        return $failure;
    }
}
