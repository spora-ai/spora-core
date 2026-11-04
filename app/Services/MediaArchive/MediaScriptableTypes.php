<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * The MIME types a browser parses as a *document* that can carry script,
 * and the headers a response carrying them must carry.
 *
 * Two facts make these types different from the raster formats the archive
 * also stores. A browser parses `text/html` and `image/svg+xml` as markup:
 * both can carry `<script>`, `onload=` handlers, `<foreignObject>`, and
 * external references. Served from the operator's own origin, that is script
 * execution in the operator's origin. The raster formats cannot — a PNG has
 * no execution model.
 *
 * The consequence is a *serving* rule, not an ingest rule. The bytes must be
 * neutralised where they leave the server, because the rule then covers
 * uploads, derivatives, and any future producer alike — a rule enforced at
 * one choke point cannot cover a path that does not pass through it. That is
 * why the ban on SVG that shipped with the archive leaked: it was enforced
 * at the upload gate only, and the derivative path never consulted it.
 *
 * `nosniff` is on every media response, script-capable or not. Without it a
 * browser may re-interpret the declared type, so a `text/plain` response
 * holding HTML could be sniffed into `text/html`. The `sandbox` directive
 * puts the response in an opaque origin with scripting and plugins disabled:
 * the document still renders, it simply cannot reach the operator's origin.
 *
 * `allow-scripts` is deliberately absent from the sandbox value here. The
 * one route that may run script — the embed route — passes its own
 * `sandbox allow-scripts` and is authenticated, so it is a deliberate act by
 * a privileged user rather than an accident reachable through a shared link.
 */
final class MediaScriptableTypes
{
    /**
     * MIME types that carry an execution model when a browser renders them
     * as a document.
     *
     * `text/html` is the obvious one. `image/svg+xml` is XML that a browser
     * parses as a document, and the script elements are as available in it
     * as in HTML. `application/xhtml+xml` is the XML flavour of HTML and
     * carries the same script elements.
     *
     * The list is a closed one on purpose. A type that is not named here is
     * served as an ordinary file, so adding a type is a decision with
     * consequences — not an oversight that fails safe.
     *
     * This is the *serving* vocabulary: it decides what the sandbox policy
     * covers. What may be *created* is a narrower question, answered by
     * {@see MediaAllowedTypesService::CREATION_MIME_TYPES} — a producer
     * that emits a MIME nobody can produce is still served safely, but
     * offering it for upload is a separate decision.
     */
    public const SCRIPTABLE_MIME_TYPES = [
        'text/html',
        'image/svg+xml',
        'application/xhtml+xml',
    ];

    /**
     * Whether a MIME type is script-capable when rendered as a document.
     *
     * The comparison is on the type's lowercased form with any parameters
     * stripped (`text/html; charset=utf-8` is `text/html`), because the
     * stored `mime_type` column carries whatever the sniffer produced and
     * that may include a charset.
     */
    public static function isScriptable(?string $mime): bool
    {
        return in_array(self::normalize($mime), self::SCRIPTABLE_MIME_TYPES, true);
    }

    /**
     * The headers a media response must carry.
     *
     * `nosniff` on every response; `sandbox` on the script-capable ones.
     * The per-type split is deliberate — a PNG response with `sandbox` would
     * be harmless but would also be a lie about what the rule protects, and
     * a rule that applies to everything protects nothing in particular.
     *
     * @return array<string, string>
     */
    public static function headersFor(?string $mime): array
    {
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        if (self::isScriptable($mime)) {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        return $headers;
    }

    /**
     * The `Content-Security-Policy` value for the embed route.
     *
     * `sandbox` without `allow-same-origin` gives the framed document an
     * opaque origin, so it cannot read `document.cookie`, cannot reach
     * `localStorage`, and cannot touch the parent document. `allow-scripts`
     * is present only when the operator has enabled it, and
     * `allow-same-origin` is never present: with it, a same-origin framed
     * document could remove its own sandbox attribute and be no more
     * contained than an unsandboxed one.
     *
     * `frame-ancestors 'self'` keeps another site from framing the embed
     * route — the document may run script, and a hostile page framing it
     * would otherwise be embedding live script on the operator's domain.
     */
    public static function embedPolicy(bool $allowScripts): string
    {
        $sandbox = $allowScripts ? 'sandbox allow-scripts' : 'sandbox';

        return $sandbox . "; frame-ancestors 'self'";
    }

    private static function normalize(?string $mime): string
    {
        if ($mime === null) {
            return '';
        }

        $semicolon = strpos($mime, ';');
        if ($semicolon !== false) {
            $mime = substr($mime, 0, $semicolon);
        }

        return strtolower(trim($mime));
    }
}
