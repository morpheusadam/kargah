<?php

namespace Modules\Social\Services\Publishers;

/**
 * For a destination that will not take image bytes, and fetches them instead.
 *
 * Five of the seventeen destinations work this way, and they are not a family:
 * Instagram and Threads take an `image_url` on a container and Meta's servers go
 * and download it; Slack renders a picture as an `image` block pointing at a URL
 * it fetches; DEV.to and Hashnode each take a single cover image as a URL and
 * have no upload endpoint at all. Different companies, different reasons, one
 * consequence — **a picture that only exists behind Kargah's `auth` middleware,
 * on a machine with no public address, cannot reach any of them.**
 *
 * That judgement was written out four times before this trait existed — in
 * `MetaGraph`, in `SlackPublisher`, and once in each of the two Blog drivers —
 * with the same twelve-line host test copied verbatim into all four. Four copies
 * of one rule is four chances for it to drift, and the failure it prevents is
 * precisely the confusing one: a Graph error about a media download failing
 * reads as "Instagram is broken" rather than "this laptop is not on the
 * internet".
 *
 * **The judgement is shared; the sentence is not, and that is the point.** The
 * five destinations do not agree on what to *do* about it. Instagram has no post
 * without a picture, so it refuses outright. Slack refuses the picture but says
 * the copy alone would have gone out. A DEV.to article is a perfectly good
 * article without a cover, so it drops the cover and publishes. So this trait
 * offers a question — `installIsFetchable()` — and a ready-made sentence for the
 * two that refuse, and each driver decides. A trait that threw would have made
 * that decision for all five.
 *
 * **What it does not do.** It cannot prove an install *is* reachable, and it does
 * not try: a public DNS name behind a firewall passes here and then fails at the
 * far end. It catches the case that is true on every developer machine and on
 * every install somebody has just put up behind `php artisan serve`, and stays
 * quiet about the case it cannot judge. A guard that guessed the other way would
 * refuse posts that would have worked, which is the more expensive mistake.
 */
trait FetchesOwnMedia
{
    /**
     * Whether a remote service could fetch anything from this install.
     *
     * Asked against `url('/')` rather than `config('app.url')` on purpose: the
     * signed link is built by the same URL generator, so this checks the host
     * that will actually be handed over rather than a second value that is
     * merely meant to agree with it.
     *
     * The judgement is deliberately crude — a loopback address, a private range,
     * a development TLD, or a bare hostname with no dot in it.
     */
    protected function installIsFetchable(): bool
    {
        $host = $this->installHost();

        return ! ($host === ''
            || $host === 'localhost'
            || ! str_contains($host, '.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.internal')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false));
    }

    /** The host a remote service would be handed, lowercased, or '' when APP_URL is unset. */
    protected function installHost(): string
    {
        return strtolower((string) parse_url(url('/'), PHP_URL_HOST));
    }

    /** The host as it should read inside a sentence, naming the empty case rather than printing nothing. */
    protected function installHostForHumans(): string
    {
        $host = $this->installHost();

        return $host === '' ? 'an address that is not set' : $host;
    }

    /**
     * Why this destination could not fetch the picture, or null if it could.
     *
     * For the drivers that refuse. The ones that merely drop the cover want
     * `installIsFetchable()` and a sentence of their own, because "the article
     * went out without its cover" and "the post did not go out" are different
     * things to tell somebody.
     *
     * @param  string  $who  the destination's own name, so the sentence names the service rather than the software
     */
    protected function unreachableInstallReason(string $who = 'This destination'): ?string
    {
        if ($this->installIsFetchable()) {
            return null;
        }

        return $who.' downloads the picture from this install rather than being sent it, and this install answers on “'
            .$this->installHostForHumans()
            .'”, which cannot be reached from outside. Nothing is wrong with the post or the credential: set APP_URL '
            .'to a public https address — a real domain, or a tunnel while you are developing — and it will publish';
    }
}
