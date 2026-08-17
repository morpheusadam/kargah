<?php

namespace Modules\Social\Services\Curation;

use Modules\Social\Models\CurationWindow;
use Modules\Social\Support\Networks;

/**
 * What one network needs its copy to be.
 *
 * Assembled from two places that each know half of it: `Networks::all()` knows
 * the platform's hard limits — 280 characters on X, 2,200 on Instagram, and
 * Telegram's caption dropping from 4,096 to 1,024 the moment a picture is
 * attached — and `curation_windows` knows the editorial budget the operator set.
 * Neither is derivable from the other, and the copywriter needs both in one
 * object because it writes for all of them in a single request.
 *
 * 🔴 **`limit` already accounts for the image.** Telegram's caption limit is the
 * reason this class takes `withImage` at all: writing 3,000 characters for a
 * network whose message limit is 4,096 and then attaching a picture produces a
 * 400 at send time, which is how it was found the first time.
 */
final readonly class NetworkBrief
{
    public function __construct(
        public string $network,
        public string $label,
        public int $limit,
        public int $hashtagsMin,
        public int $hashtagsMax,
    ) {}

    /**
     * The brief for one network, given the window the operator configured.
     *
     * `$window` is nullable because a network with no row falls back to the
     * configured default — connecting a seventeenth account must not need a
     * migration before it can be posted to.
     */
    public static function for(string $network, ?CurationWindow $window, bool $withImage): self
    {
        $catalogue = Networks::all()[$network] ?? [];
        $default = (array) config('social.curation.default_window', []);

        $limit = (int) ($catalogue['limit'] ?? 1000);

        // The caption limit only applies when something is actually attached, and
        // it is always the smaller of the two when it does.
        $caption = $catalogue['media']['caption_limit'] ?? null;

        if ($withImage && is_int($caption)) {
            $limit = min($limit, $caption);
        }

        return new self(
            network: $network,
            label: (string) ($catalogue['label'] ?? ucfirst($network)),
            limit: $limit,
            hashtagsMin: (int) ($window?->hashtags_min ?? $default['hashtags_min'] ?? 2),
            hashtagsMax: (int) ($window?->hashtags_max ?? $default['hashtags_max'] ?? 3),
        );
    }

    /**
     * How much of the limit the copy itself may use.
     *
     * The hashtags, the source line and the blank lines between them all come out
     * of the same allowance, and a model told "write 280 characters" for X will
     * write 280 and leave no room for the two hashtags it was also told to add.
     * Roughly fifteen characters per hashtag is measured against the Persian tag
     * vocabulary, which runs long — `#حریم_خصوصی` is eleven characters before the
     * separator.
     */
    public function bodyBudget(): int
    {
        return max(80, $this->limit - ($this->hashtagsMax * 15) - 40);
    }
}
