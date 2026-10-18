<?php

declare(strict_types=1);

namespace Spora\Auth;

use Spora\Models\AgentApiKey;

/**
 * Mints and verifies Agent API keys.
 *
 * A key is `spk_<id>_<secret>`: the id half is a random hex string
 * that also serves as the row's primary key, so verification is a single
 * indexed lookup rather than a scan comparing every digest.
 */
final class AgentApiKeyIssuer
{
    private const PREFIX = 'spk_';

    private const ID_BYTES = 16;

    private const SECRET_BYTES = 32;

    /**
     * @return array{0: AgentApiKey, 1: string} the stored row and the plaintext key, the only moment it exists
     */
    public function issue(int $agentId, string $name, ?int $createdBy = null): array
    {
        $id = bin2hex(random_bytes(self::ID_BYTES));
        $secret = $this->base64UrlEncode(random_bytes(self::SECRET_BYTES));

        $key = AgentApiKey::create([
            'id' => $id,
            'agent_id' => $agentId,
            'name' => $name,
            'secret_hash' => $this->hashSecret($secret),
            'created_by' => $createdBy,
        ]);

        return [$key, self::PREFIX . $id . '_' . $secret];
    }

    /**
     * Constant-time compare against the stored digest.
     *
     * A plain SHA-256 rather than bcrypt or argon is deliberate: the input
     * is 32 bytes of CSPRNG output, so there is no dictionary to attack and
     * no benefit from a slow KDF — but the hash is on the request path of
     * every Agent API call. Swapping in a password hash would cost latency
     * without buying anything.
     *
     * @return array{0: bool, 1: string} whether the secret matched, and the key id when it did
     */
    public function verify(string $presented, ?AgentApiKey $key): array
    {
        if ($key === null) {
            return [false, ''];
        }

        return [hash_equals($key->secret_hash, $this->hashSecret($presented)), $key->id];
    }

    public function secretFrom(string $keyString): ?string
    {
        $prefix = self::PREFIX;

        if (!str_starts_with($keyString, $prefix)) {
            return null;
        }

        $body = substr($keyString, strlen($prefix));
        $separator = strpos($body, '_');

        if ($separator === false) {
            return null;
        }

        $id = substr($body, 0, $separator);
        $secret = substr($body, $separator + 1);

        if (!preg_match('/^[a-f0-9]{32}$/', $id) || $secret === '') {
            return null;
        }

        return $secret;
    }

    public function idFrom(string $keyString): ?string
    {
        $prefix = self::PREFIX;

        if (!str_starts_with($keyString, $prefix)) {
            return null;
        }

        $body = substr($keyString, strlen($prefix));
        $separator = strpos($body, '_');

        if ($separator === false) {
            return null;
        }

        $id = substr($body, 0, $separator);

        return preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? $id : null;
    }

    public function compose(string $id, string $secret): string
    {
        return self::PREFIX . $id . '_' . $secret;
    }

    private function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
