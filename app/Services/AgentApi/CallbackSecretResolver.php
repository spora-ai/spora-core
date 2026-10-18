<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

use Spora\Models\AgentApiKey;

/**
 * Supplies the callback-signing secret for a key id.
 *
 * Spora stores only the SHA-256 of a key's secret and cannot recover it, so
 * a receiver that wants to verify `X-Spora-Signature` would need Spora to
 * hold the secret. Instead the signature is keyed on the *digest* the row
 * already stores: the receiver holds the plaintext secret it was issued and
 * computes `hmac(timestamp + "." + body, sha256(secret))`, which Spora can
 * reproduce from `secret_hash` alone.
 *
 * Rotating a key rotates its signing key with it, and a revoked key cannot
 * have its callbacks forged — which is the property that makes the digest
 * sufficient here.
 */
final class CallbackSecretResolver
{
    public function for(string $keyId): ?string
    {
        $key = AgentApiKey::find($keyId);

        return $key === null ? null : $key->secret_hash;
    }
}
