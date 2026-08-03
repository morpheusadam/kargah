<?php

namespace Tests\Unit;

use Modules\Project\Support\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * Markdown rendered to HTML that is safe to echo unescaped.
 *
 * This is the one place in the project that decides what `{!! !!}` is allowed
 * to show. A card description and a card comment are both user input, so the
 * property that matters is not "does it render bold text" — it is "does a
 * planted script tag or a javascript: link ever reach the page".
 */
class MarkdownTest extends TestCase
{
    public function test_a_planted_script_tag_does_not_survive_conversion(): void
    {
        $html = Markdown::toHtml("Before the attack.\n\n<script>alert(1)</script>\n\nAfter the attack.");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('Before the attack.', $html);
        $this->assertStringContainsString('After the attack.', $html);
    }

    public function test_a_javascript_link_does_not_survive_conversion(): void
    {
        $html = Markdown::toHtml('Click [here](javascript:alert(1)) to continue.');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_an_inline_html_attribute_attack_does_not_survive_conversion(): void
    {
        $html = Markdown::toHtml('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_ordinary_formatting_still_works(): void
    {
        $html = Markdown::toHtml("**Bold**, _italic_, `code`, and a [link](https://example.com).\n\n- one\n- two");

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
    }

    public function test_a_mailto_link_is_kept(): void
    {
        $html = Markdown::toHtml('Reach me at [nima@example.com](mailto:nima@example.com).');

        $this->assertStringContainsString('href="mailto:nima@example.com"', $html);
    }

    public function test_blank_and_null_input_render_nothing(): void
    {
        $this->assertSame('', Markdown::toHtml(null));
        $this->assertSame('', Markdown::toHtml('   '));
        $this->assertSame('', Markdown::toHtml(''));
    }
}
