<?php

namespace Modules\Social\Services\Curation;

use Modules\Core\Contracts\TextGenerationFailed;
use Modules\Core\Contracts\TextGenerator;

/**
 * One story in, one piece of Persian copy per network out.
 *
 * **One request for every network, not one per network.** Four separate requests
 * would cost four times the quota — Gemini's free tier is counted per day, and
 * this feature has to survive on it — and, worse, would produce four independently
 * written posts that disagree about what the story said. One request sees every
 * brief at once and writes variations of a single reading.
 *
 * ---
 *
 * ## Why the copy differs per network at all
 *
 * Not for the sake of variety. Three separate reasons, each measured:
 *
 * **The limits are not comparable.** X allows 280 characters and Instagram 2,200.
 * The same paragraph cannot serve both, and truncating one to fit the other
 * produces a post that stops mid-sentence.
 *
 * **The hashtag budgets are opposites.** Ten or more hashtags on LinkedIn risks a
 * 30–50% reach penalty and its 2026 algorithm does not read them for
 * classification at all; Instagram allows thirty and dense tagging is normal
 * there. The owner asked for hashtag-rich copy, and honouring that uniformly
 * would quietly damage the network they named as most important. Per-network
 * budgets are what let both instructions be obeyed.
 *
 * **Identical text posted to several networks is itself a risk.** It reads as
 * automation to a person and looks like it to a platform, which is the shape of
 * thing LinkedIn's own guidance is about.
 *
 * ## The SEO instruction, in one sentence per network
 *
 * Instagram shows 125 characters before "more" and now ranks captions as search
 * text; LinkedIn shows roughly 140 and reads the copy rather than the tags. So
 * the prompt asks for the specific, searchable words — the product, the company,
 * the number, the CVE — to be inside that opening rather than after it. That is
 * the whole of it, and it is more effective than any amount of tagging.
 */
class Copywriter
{
    /**
     * The most article text worth sending.
     *
     * Enough that the model is summarising an article rather than rewriting a
     * headline — which was the single biggest quality difference measured on the
     * pipeline this is ported from — and bounded because the opening of a news
     * article carries the story and the rest carries background.
     */
    private const MAX_ARTICLE_CHARS = 6000;

    public function __construct(private readonly TextGenerator $generator) {}

    public function unavailableReason(): ?string
    {
        return $this->generator->unavailableReason();
    }

    /**
     * Write the day's post, or decide it is not worth posting.
     *
     * Returns null when the model judges the story off-topic or unpostable, which
     * is a real editorial answer and not a failure — the caller moves to the next
     * candidate. A failure throws.
     *
     * @param  list<NetworkBrief>  $briefs
     * @return array<string, Copy>|null keyed by network
     *
     * @throws TextGenerationFailed
     */
    public function write(Story $story, ?string $articleText, array $briefs): ?array
    {
        if ($briefs === []) {
            return null;
        }

        $answer = $this->decode(
            $this->generator->generate(
                prompt: $this->prompt($story, $articleText, $briefs),
                system: 'You are the editor of a Persian-language technology channel. '
                    .'You answer with one JSON object and nothing else.',
                // Generous: an Instagram caption plus four other networks in one
                // answer is a lot of Persian, and Persian costs more tokens per
                // character than English does.
                maxTokens: 4096,
            ),
        );

        if (($answer['skip'] ?? false) === true) {
            return null;
        }

        // What a Latin proper-noun hashtag has to appear in to be allowed. The
        // article as well as the headline, because the company that owns the
        // vulnerability is frequently named in the body and not in the title.
        $sourceText = $story->fullText()."\n".(string) $articleText;

        $written = [];

        foreach ($briefs as $brief) {
            $copy = $this->copyFor($brief, $answer, $story, $sourceText);

            if ($copy !== null) {
                $written[$brief->network] = $copy;
            }
        }

        // Every network refused, or the model answered with a shape nothing could
        // be read out of. Either way there is nothing to publish, and saying so is
        // better than publishing an empty post.
        return $written === [] ? null : $written;
    }

    /**
     * The instruction.
     *
     * Built rather than templated because the budgets are per network and per
     * install: the numbers below come from `curation_windows` rows the operator
     * edits, and a prompt that stated them as fixed text would drift out of step
     * with the settings page the first time somebody moved a slider.
     *
     * @param  list<NetworkBrief>  $briefs
     */
    private function prompt(Story $story, ?string $articleText, array $briefs): string
    {
        $vocabulary = implode(' ', Hashtags::keys());

        $networkRules = implode("\n", array_map($this->ruleFor(...), $briefs));

        $source = $story->publisher ?? $story->label;

        $article = trim((string) $articleText);
        $article = $article === ''
            ? $story->fullText()
            : mb_substr($article, 0, self::MAX_ARTICLE_CHARS);

        $networkList = implode(', ', array_map(fn (NetworkBrief $b): string => $b->network, $briefs));

        return <<<PROMPT
            متن زیر یک خبر تکنولوژی است، به هر زبانی که باشد. خروجی تو فارسی است.

            ## اول: آیا این خبر اصلاً ارزش انتشار دارد؟

            کانال فقط درباره‌ی این موضوعات می‌نویسد:
            تکنولوژی · برنامه‌نویسی · هوش مصنوعی · هک و امنیت سایبری · شبکه و اینترنت ·
            سانسور و فیلترینگ اینترنت · قطعی اینترنت · حقوق دیجیتال و حریم خصوصی

            سیاستِ اینترنت جزو موضوعات ماست، سیاست عمومی نه. خبر فیلترینگ، قطعی اینترنت،
            مسدودسازی سرویس، قانون‌گذاری روی داده و گزارش سازمان‌های حقوق دیجیتال را نگه
            دار — مخصوصاً وقتی به ایران مربوط است.

            اگر موضوع *اصلی* متن یکی از اینها نیست، فقط این را برگردان و هیچ چیز دیگر:
            {"skip": true, "reason": "<یک جمله، فارسی>"}

            اینها skip می‌شوند حتی اگر جالب باشند: حادثه‌ی صنعتی و انرژی و خودرو و
            هوافضا بدون جنبه‌ی نرم‌افزاری؛ پزشکی، زیست‌شناسی، اقلیم، فضا، فیزیک؛ سیاست
            عمومی، اقتصاد کلان، جنگ، جرم عادی؛ سرگرمی، فیلم، ورزش؛ تاریخ و فلسفه و هنر؛
            تبلیغ، کد تخفیف، معرفی محصول برای فروش.

            ## دوم: اگر ارزش دارد، برای هر شبکه یک متن بنویس

            شبکه‌ها: {$networkList}

            {$networkRules}

            قواعد مشترک همه‌ی شبکه‌ها:

            - فارسی بنویس، ولی اصطلاح فنی و نام شرکت و محصول را لاتین نگه دار.
              «vulnerability» بنویس نه «آسیب‌پذیری» وقتی اسم خاص است، و CVE-2026-1234
              را دست نزن.
            - محاوره‌ای ننویس. خشک و ماشینی هم ننویس.
            - هیچ مقدمه‌ای مثل «در این خبر آمده است» یا «به تازگی» ننویس. مستقیم برو
              سر اصل مطلب.
            - فقط از چیزی بنویس که در متن هست. هیچ عدد، نام، تاریخ یا ادعایی از خودت
              اضافه نکن. اگر متن چیزی را نگفته، تو هم نگو.
            - کلیدواژه‌های واقعی — نام محصول، نام شرکت، شماره‌ی CVE، عدد — را در
              **جمله‌ی اول** بگذار، نه در پایان. این مهم‌ترین قاعده‌ی این پرامپت است:
              اینستاگرام فقط ۱۲۵ کاراکتر اول را قبل از «more» نشان می‌دهد و همان را
              برای جستجو می‌خواند، و لینکدین حدود ۱۴۰ کاراکتر اول را.
            - کلیک‌بیت ننویس. «باورتان نمی‌شود» و «همه‌چیز تغییر کرد» ممنوع.
            - اگر متن آن‌قدر محتوا نداشت، کوتاه‌تر بنویس. کش دادن ممنوع.

            ## هشتگ‌ها

            فقط از این کلیدهای بسته انتخاب کن و کلید جدید نساز:

            {$vocabulary}

            اولین هشتگ هر شبکه باید یکی از این پنج دسته باشد: AI SECURITY PROGRAMMING
            NETWORK TECH — همان یکی که موضوع اصلی متن است.

            یک استثنا: اگر به هشتگ بیشتری نیاز داری (اینستاگرام)، می‌توانی اسم خاصِ
            لاتینی که در خودِ متن آمده را هم به شکل هشتگ بیاوری، مثل #Cloudflare یا
            #OpenAI. فقط اسم خاص لاتین، بدون فاصله، بدون فارسی. اسم فارسی از خودت
            نساز.

            ## قالب خروجی

            دقیقاً یک شیء JSON، بدون هیچ متن دیگری، بدون ```:

            {"skip": false, "networks": { "<network>": {"body": "<متن فارسی>", "hashtags": ["<کلید یا #LatinName>"]} }}

            `body` هشتگ ندارد. هشتگ‌ها فقط در آرایه‌ی `hashtags` می‌آیند.

            ## خبر

            منبع: {$source}
            تیتر: {$story->title}

            ---
            {$article}
            ---
            PROMPT;
    }

    /** The per-network line, with that network's own numbers in it. */
    private function ruleFor(NetworkBrief $brief): string
    {
        return sprintf(
            '- **%s** (`%s`): حداکثر %d کاراکتر برای body، و %d تا %d هشتگ.',
            $brief->label,
            $brief->network,
            $brief->bodyBudget(),
            $brief->hashtagsMin,
            $brief->hashtagsMax,
        );
    }

    /**
     * The model's answer, decoded, with the two ways it habitually wraps JSON undone.
     *
     * 🔴 Models fence JSON in ```json blocks constantly, whatever the prompt says,
     * and some prepend a sentence of explanation before the object. Both are
     * recoverable and neither is worth failing a day's post over — so the fence is
     * stripped and, failing that, the outermost braces are taken. What is *not*
     * recovered is a body that is not JSON at all, because guessing at that would
     * mean publishing whatever the model happened to say.
     *
     * @return array<string, mixed>
     *
     * @throws TextGenerationFailed
     */
    private function decode(string $raw): array
    {
        $text = trim($raw);

        if (str_starts_with($text, '```')) {
            $text = trim(preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text) ?? $text);
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            $first = mb_strpos($text, '{');
            $last = mb_strrpos($text, '}');

            if ($first !== false && $last !== false && $last > $first) {
                $decoded = json_decode(mb_substr($text, $first, $last - $first + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw TextGenerationFailed::refused(
                'the copywriter',
                'the answer was not JSON: '.mb_substr($text, 0, 200),
            );
        }

        return $decoded;
    }

    /**
     * One network's copy, validated against its brief.
     *
     * Everything the model got wrong is corrected here rather than retried,
     * because the corrections are all safe in one direction: too many hashtags are
     * dropped, an invented one is dropped, a body that overran is trimmed on a
     * word boundary. A retry would cost another request against a daily quota to
     * fix something arithmetic already fixed.
     *
     * @param  array<string, mixed>  $answer
     */
    private function copyFor(NetworkBrief $brief, array $answer, Story $story, string $sourceText): ?Copy
    {
        $networks = $answer['networks'] ?? [];

        if (! is_array($networks)) {
            return null;
        }

        $written = $networks[$brief->network] ?? null;

        if (! is_array($written)) {
            return null;
        }

        $body = trim((string) ($written['body'] ?? ''));

        if ($body === '') {
            return null;
        }

        $copy = new Copy(
            network: $brief->network,
            body: $body,
            hashtags: $this->hashtagsFor($brief, $written['hashtags'] ?? [], $sourceText),
            sourceUrl: $story->url,
        );

        return $copy->within($brief->limit);
    }

    /**
     * The hashtags this network gets: resolved, deduplicated, and inside budget.
     *
     * The ceiling is enforced here and not merely requested in the prompt, because
     * it is the one instruction whose breach has a measured cost — LinkedIn's
     * reach penalty starts at ten — and a model that miscounts is far more likely
     * than one that refuses.
     *
     * @return list<string>
     */
    private function hashtagsFor(NetworkBrief $brief, mixed $raw, string $sourceText): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $tags = [];

        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $tag = Hashtags::tagFor($entry);

            if ($tag === null) {
                // The one exception to the closed vocabulary: a Latin proper noun
                // that appears in the story. One spelling, so it cannot drift, and
                // it has to be a name the article actually used.
                $candidate = str_starts_with(trim($entry), '#') ? trim($entry) : '#'.trim($entry);

                $tag = Hashtags::isAcceptableLatinTag($candidate, $sourceText) ? $candidate : null;
            }

            if ($tag !== null && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }

            if (count($tags) >= $brief->hashtagsMax) {
                break;
            }
        }

        return $tags;
    }
}
