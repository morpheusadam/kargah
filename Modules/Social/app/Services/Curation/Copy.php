<?php

namespace Modules\Social\Services\Curation;

/**
 * The finished Persian copy for one network.
 *
 * Assembly lives here rather than in the copywriter because the pieces are worth
 * keeping apart until the last moment: the settings page wants to show the body
 * and the hashtag count separately, and `post_targets.body_override` wants the
 * one assembled string. Two callers, two needs, one place that knows the order
 * they go in.
 */
final readonly class Copy
{
    /** @param  list<string>  $hashtags */
    public function __construct(
        public string $network,
        public string $body,
        public array $hashtags,
        public ?string $sourceUrl = null,
    ) {}

    /**
     * The text this network is sent.
     *
     * Body, then a blank line, then the hashtags on their own line. The hashtags
     * are last on every network, and not because it looks tidy: Instagram reads
     * the *opening* of a caption for ranking and shows only the first 125
     * characters before "more", so a hashtag block at the top spends the only
     * part anybody sees on tags. The same shape happens to be right on LinkedIn,
     * whose 2026 algorithm reads the copy rather than the tags.
     *
     * The link is not appended here. It goes on the post as a real link — an
     * inline keyboard button on Telegram, the article URL in the body on
     * LinkedIn — because a bare URL pasted at the end of a caption is unclickable
     * on Instagram and merely ugly everywhere else.
     */
    public function text(): string
    {
        $parts = [trim($this->body)];

        if ($this->hashtags !== []) {
            $parts[] = implode(' ', $this->hashtags);
        }

        return implode("\n\n", array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** Characters as the networks count them, which is codepoints and not bytes. */
    public function length(): int
    {
        return mb_strlen($this->text());
    }

    /**
     * The copy, shortened until it fits, hashtags kept.
     *
     * Trimming the body rather than the tags is the deliberate half of this. The
     * tags are a fixed, small, deliberately budgeted set and dropping one changes
     * what the post is filed under; the body's last sentence is the least
     * important thing in it. The cut falls on a word boundary and takes an
     * ellipsis, because a Persian sentence severed mid-word reads as a bug.
     */
    public function within(int $limit): self
    {
        if ($this->length() <= $limit) {
            return $this;
        }

        $tagLength = $this->hashtags === []
            ? 0
            : mb_strlen(implode(' ', $this->hashtags)) + 2;

        $room = max(20, $limit - $tagLength - 1);
        $body = mb_substr(trim($this->body), 0, $room);

        $lastSpace = mb_strrpos($body, ' ');

        if ($lastSpace !== false && $lastSpace > $room * 0.6) {
            $body = mb_substr($body, 0, $lastSpace);
        }

        return new self($this->network, rtrim($body).'…', $this->hashtags, $this->sourceUrl);
    }
}
