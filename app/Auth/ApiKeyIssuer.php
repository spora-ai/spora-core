<?php

declare(strict_types=1);

namespace Spora\Auth;

use InvalidArgumentException;
use Spora\Models\ApiKey;

/**
 * Mints and verifies API keys.
 *
 * A key is `spk_<id>_<secret>`: the id half is a random hex string
 * that also serves as the row's primary key, so verification is a single
 * indexed lookup rather than a scan comparing every digest.
 *
 * The format does not carry the scope. A receiver does not need to know
 * which surface a key was minted for, and one format means one parser.
 */
final class ApiKeyIssuer
{
    private const PREFIX = 'spk_';

    private const ID_BYTES = 16;

    private const SECRET_BYTES = 32;

    /**
     * The exact shape of a key. Kept as a constant because `parse()` and
     * the tests both need to agree on it, and a second copy of a regex is a
     * second thing to forget to update.
     */
    private const SHAPE = '/^spk_([a-f0-9]{32})_([A-Za-z0-9_-]+)$/';

    /**
     * Mint a key for one scope.
     *
     * The target follows from the scope: an agent key names an agent, a
     * group key names a group, a global key names neither. The scope
     * refuses a target it does not match, so a row whose columns disagree
     * with its scope cannot be written from here.
     *
     * @return array{0: ApiKey, 1: string} the stored row and the plaintext key, the only moment it exists
     *
     * @throws InvalidArgumentException when the target does not match the scope
     */
    public function issue(
        ApiKeyScope $scope,
        string $name,
        ?int $agentId = null,
        ?int $groupId = null,
        ?int $createdBy = null,
    ): array {
        $scope->assertConsistent($agentId, $groupId);

        $id = bin2hex(random_bytes(self::ID_BYTES));
        $secret = $this->base64UrlEncode(random_bytes(self::SECRET_BYTES));

        $key = ApiKey::create([
            'id' => $id,
            'scope' => $scope->value,
            'agent_id' => $agentId,
            'group_id' => $groupId,
            'name' => $name,
            'secret_hash' => $this->hashSecret($secret),
            'created_by' => $createdBy,
        ]);

        return [$key, $this->compose($id, $secret)];
    }

    /**
     * Constant-time compare against the stored digest.
     *
     * A plain SHA-256 rather than bcrypt or argon is deliberate: the input
     * is 32 bytes of CSPRNG output, so there is no dictionary to attack and
     * no benefit from a slow KDF — but the hash is on the request path of
     * every API call. Swapping in a password hash would cost latency
     * without buying anything.
     *
     * @return array{0: bool, 1: string} whether the secret matched, and the key id when it did
     */
    public function verify(string $presented, ?ApiKey $key): array
    {
        if ($key === null) {
            return [false, ''];
        }

        return [hash_equals($key->secret_hash, $this->hashSecret($presented)), $key->id];
    }

    /**
     * Split a presented key into its id and secret, or return null.
     *
     * Matches the whole shape rather than splitting on the first underscore:
     * the secret is base64url, whose alphabet *includes* `_`, so a
     * `strpos($body, '_')` split reads the wrong boundary roughly half the
     * time and would hand a caller a plausible-looking id that belongs to
     * nobody.
     *
     * @return array{0: string, 1: string}|null
     */
    public function parse(string $keyString): ?array
    {
        if (preg_match(self::SHAPE, $keyString, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public function secretFrom(string $keyString): ?string
    {
        return $this->parse($keyString)[1] ?? null;
    }

    public function idFrom(string $keyString): ?string
    {
        return $this->parse($keyString)[0] ?? null;
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
