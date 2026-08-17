<?php

namespace Modules\Social\Services\Curation;

/**
 * The Persian hashtag vocabulary, deliberately closed.
 *
 * 🔴 **A hashtag that is spelled slightly differently every day accumulates
 * nothing.** Telegram's in-app search matches the exact string, Instagram files a
 * post under the exact tag, and a channel that writes `#هوش_مصنوعی` on Monday and
 * `#هوش‌مصنوعی` on Tuesday — one with an underscore, one with a zero-width
 * non-joiner — has two tags with one post each instead of one tag with two. A
 * language model asked to invent Persian hashtags will produce exactly that
 * drift, confidently, and it is invisible in review because both spellings look
 * right.
 *
 * So the model chooses from this list and may not add to it, with one exception
 * named in the copywriter's prompt: a proper noun already written in Latin —
 * `#OpenAI`, `#Cloudflare` — is stable by construction, because there is only one
 * way to spell it. That exception is what makes an eighteen-tag Instagram caption
 * possible without the vocabulary having to contain eighteen general topics.
 *
 * The list is code rather than a settings row, for the reason
 * `Modules\Platform\Support\AssistantDrivers` is: it is not an install decision.
 * Adding a topic means the channel now covers that topic, which is an editorial
 * change, and the person making it is editing the prompt in the same breath.
 */
final class Hashtags
{
    /**
     * The five subjects the channel is about. Exactly one is chosen per post.
     *
     * @var array<string, string>
     */
    public const TOPICS = [
        'AI' => '#هوش_مصنوعی',
        'SECURITY' => '#امنیت',
        'PROGRAMMING' => '#برنامه_نویسی',
        'NETWORK' => '#شبکه',
        'TECH' => '#تکنولوژی',
    ];

    /**
     * The specific tags, which are the ones anybody actually searches.
     *
     * Nobody looks for `#تکنولوژی`; people look for `#باج_افزار`. The topic tag
     * files the post, and these are what let it be found.
     *
     * @var array<string, string>
     */
    public const SPECIFIC = [
        'LINUX' => '#لینوکس',
        'WINDOWS' => '#ویندوز',
        'ANDROID' => '#اندروید',
        'APPLE' => '#اپل',
        'GOOGLE' => '#گوگل',
        'MICROSOFT' => '#مایکروسافت',
        'OPENSOURCE' => '#متن_باز',
        'PYTHON' => '#پایتون',
        'JAVASCRIPT' => '#جاوااسکریپت',
        'RUST' => '#Rust',
        'MALWARE' => '#بدافزار',
        'RANSOMWARE' => '#باج_افزار',
        'BREACH' => '#نشت_اطلاعات',
        'VULNERABILITY' => '#آسیب_پذیری',
        'PHISHING' => '#فیشینگ',
        'CRYPTO' => '#رمزارز',
        'CLOUD' => '#کلاد',
        'DATABASE' => '#دیتابیس',
        'MOBILE' => '#موبایل',
        'HARDWARE' => '#سخت_افزار',
        'CHIP' => '#تراشه',
        'STARTUP' => '#استارتاپ',
        'PRIVACY' => '#حریم_خصوصی',
        'LLM' => '#مدل_زبانی',
        'CHATBOT' => '#چت_بات',
        'BROWSER' => '#مرورگر',
        'GAMEDEV' => '#بازی_سازی',
        'DEVOPS' => '#DevOps',
        'CENSORSHIP' => '#فیلترینگ',
        'SHUTDOWN' => '#قطعی_اینترنت',
        'IRAN' => '#اینترنت_ایران',
        'VPN' => '#VPN',
        'SURVEILLANCE' => '#نظارت',
        'DATACENTER' => '#دیتاسنتر',
        'ENCRYPTION' => '#رمزنگاری',
        'OPENAI' => '#OpenAI',
        'CYBERATTACK' => '#حمله_سایبری',
        'PROGRAMMER' => '#برنامه_نویس',
        'DEVELOPER' => '#توسعه_دهنده',
        'TECHNEWS' => '#اخبار_تکنولوژی',
    ];

    /** Every key the model is allowed to name. */
    public static function keys(): array
    {
        return [...array_keys(self::TOPICS), ...array_keys(self::SPECIFIC)];
    }

    /** The tag for a key, or null when the model invented one. */
    public static function tagFor(string $key): ?string
    {
        $key = strtoupper(trim($key, " \t\n\r\0\x0B#"));

        return self::TOPICS[$key] ?? self::SPECIFIC[$key] ?? null;
    }

    /**
     * Whether a tag the model wrote out in full may pass the closed vocabulary.
     *
     * The one exception, and it is narrow on purpose: a Latin proper noun that
     * **appears in the story being posted**. `#Cloudflare` has exactly one
     * spelling, so it cannot drift the way a transliterated Persian tag can, and
     * it is how an eighteen-tag Instagram caption is reachable without the
     * vocabulary having to list eighteen general topics.
     *
     * Two guards, and each caught a real case in testing:
     *
     * **No underscores.** Proper nouns do not have them — Cloudflare, OpenAI,
     * Nvidia — and allowing them let `#NOT_A_REAL_KEY` through, which is what a
     * model produces when it half-remembers that it was given a list of keys.
     *
     * **It has to be in the source text.** Without this the exception is a hole
     * the size of "any Latin word", and a model that invents a company name would
     * have it published as a hashtag. Requiring the noun to appear in the story is
     * the same rule the prompt states in words, enforced.
     */
    public static function isAcceptableLatinTag(string $tag, string $sourceText): bool
    {
        if (preg_match('/^#([A-Za-z][A-Za-z0-9]{1,29})$/', $tag, $matches) !== 1) {
            return false;
        }

        return mb_stripos($sourceText, $matches[1]) !== false;
    }
}
