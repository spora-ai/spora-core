<?php

declare(strict_types=1);

namespace Spora\Services\Mail;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

final class MailTemplateRenderer
{
    /**
     * Construct a renderer with the default CommonMark + GFM environment.
     * Used by callers that don't have a DI container handy (e.g. `MailTemplate::render()`,
     * tests, and the `SystemMailer` no-container fallback) so the dependency
     * can be optional at the constructor level.
     *
     * Both {@code html_input=escape} and {@code allow_unsafe_links=false} are
     * intentional: system emails render operator-authored Markdown where
     * runtime placeholders such as {@code {{user_prompt}}} get filled with
     * untrusted task content. Raw HTML in the Markdown body is escaped to
     * entities (`<script>` → `&lt;script&gt;`) so payloads render as visible
     * text in the recipient's client rather than executing, and the default
     * `javascript:` and `vbscript:` hrefs are stripped. Operators needing a
     * raw-HTML shell can opt in via {@code body_html}, which is trusted and
     * never substituted from request data.
     */
    public static function createDefault(): self
    {
        $env = new Environment([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());

        return new self(new MarkdownConverter($env));
    }

    public function __construct(
        private readonly MarkdownConverter $converter,
    ) {}

    /**
     * @param array<string,scalar|null> $variables
     */
    public function render(array $variables, string $subject, ?string $bodyMarkdown, ?string $bodyHtml): RenderedTemplate
    {
        $replace = static function (string $content) use ($variables): string {
            return preg_replace_callback(
                '/\{\{(\w+)\}\}/',
                static fn(array $m): string => array_key_exists($m[1], $variables)
                    ? (string) $variables[$m[1]]
                    : $m[0],
                $content,
            );
        };

        $substitutedMarkdown = $bodyMarkdown !== null ? $replace($bodyMarkdown) : null;
        $substitutedHtmlShell = $bodyHtml !== null ? $replace($bodyHtml) : null;

        $renderedHtml = $substitutedMarkdown !== null
            ? $this->converter->convert($substitutedMarkdown)->getContent()
            : null;

        $finalHtml = match (true) {
            $substitutedHtmlShell !== null
                && str_contains($substitutedHtmlShell, '{markdown_html}')
                => str_replace('{markdown_html}', $renderedHtml ?? '', $substitutedHtmlShell),
            $substitutedHtmlShell !== null
                => $substitutedHtmlShell,
            default
            => $renderedHtml ?? '',
        };

        $finalText = $renderedHtml !== null
            ? self::htmlToText($renderedHtml)
            : '';

        $finalSubject = $replace($subject);

        return new RenderedTemplate($finalSubject, $finalText, $finalHtml);
    }

    /**
     * Convert rendered HTML to a plain-text alternative. `strip_tags` discards
     * `<a href>` attributes, which would strip the URLs out of links like
     * `[label](https://example.test/verify)` and produce a plain-text body
     * with no URL — useless for plain-text-only mail readers. We extract hrefs
     * alongside the stripped text and append them in a `Links:` block so every
     * URL the HTML body contains is recoverable from the text part too.
     */
    private static function htmlToText(string $html): string
    {
        $stripped = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        preg_match_all('/<a\s+[^>]*href\s*=\s*"(https?:\/\/[^"]+)"/i', $html, $matches);
        $urls = array_values(array_unique(array_map(
            static fn(string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $matches[1],
        )));

        if ($urls === []) {
            return $stripped;
        }

        $urlBlock = "\n\nLinks:\n" . implode("\n", array_map(static fn(string $u): string => '- ' . $u, $urls));
        return $stripped . $urlBlock;
    }
}

final readonly class RenderedTemplate
{
    public function __construct(
        public string $subject,
        public string $bodyText,
        public string $bodyHtml,
    ) {}
}
