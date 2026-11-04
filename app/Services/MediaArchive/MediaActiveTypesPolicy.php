<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Core\Config;
use Spora\Models\MediaAsset;

/**
 * The operator's decisions about the script-capable media formats.
 *
 * Two independent questions, and the difference matters:
 *
 *  - *May script-capable formats exist at all?* — `allowActiveTypes()`. An
 *    operator who leaves this off gets neither HTML nor SVG in the archive.
 *  - *May an embed run their scripts?* — `allowEmbedScripts()`. The embed
 *    surface is a deliberate act by a privileged user, so this is a second,
 *    separate decision rather than a consequence of the first.
 *
 * This class exists as its own seam because the two consumers cannot reach
 * each other: {@see MediaAllowedTypesService} reads the first question, and
 * it already depends on {@see MediaDerivativeService}, so the derivative
 * service — which needs the same answer — cannot depend on it back without
 * a cycle. A small policy object both sides can read is the way out.
 *
 * The values are read through {@see Config} rather than passed in, so the
 * operator's setting applies on the next request without a rebuild.
 */
final class MediaActiveTypesPolicy
{
    public function __construct(private readonly Config $config) {}

    /**
     * Whether the script-capable formats may be created.
     *
     * Off by default: an install that changes nothing gets neither HTML
     * nor SVG.
     */
    public function allowActiveTypes(): bool
    {
        return $this->config->getBool('SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES', false);
    }

    /**
     * Whether an embed may execute the scripts it carries.
     *
     * This never widens what may be created — an embed can only exist for
     * a format {@see self::allowActiveTypes()} already permitted — so it is
     * read only where an embed is being served.
     */
    public function allowEmbedScripts(): bool
    {
        return $this->allowActiveTypes()
            && $this->config->getBool('SPORA_MEDIA_ARCHIVE_ALLOW_EMBED_SCRIPTS', false);
    }

    /**
     * Whether a MIME type may be created as a media asset.
     *
     * The script-capable types are gated on the operator's toggle; every
     * other type is the allowlist service's business.
     */
    public function permitsCreationOf(string $mime): bool
    {
        if (!MediaScriptableTypes::isScriptable($mime)) {
            return true;
        }

        return $this->allowActiveTypes();
    }

    /**
     * The embed descriptor for an asset, or null when it has none.
     *
     * The server decides this and the client renders it, so the client
     * cannot widen the policy: a client that asked for scripts when the
     * operator did not allow them would get a document the route refuses
     * to serve scripts to anyway.
     *
     * `scripts` is what the iframe's `sandbox` attribute is derived from.
     * It mirrors {@see self::allowEmbedScripts()} rather than being
     * computed here, so the header the route sends and the attribute the
     * client sets cannot disagree.
     *
     * @return array{url: string, mime: string, scripts: bool}|null
     */
    public function describeEmbed(MediaAsset $asset): ?array
    {
        $mime = (string) ($asset->mime_type ?? '');
        if (!MediaScriptableTypes::isScriptable($mime) || !$this->allowActiveTypes()) {
            return null;
        }

        return [
            'url'     => '/api/v1/media/' . $asset->id . '/embed',
            'mime'    => $mime,
            'scripts' => $this->allowEmbedScripts(),
        ];
    }
}
