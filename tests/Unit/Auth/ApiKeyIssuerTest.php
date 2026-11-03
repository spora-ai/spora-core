<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use InvalidArgumentException;
use Spora\Auth\ApiKeyIssuer;
use Spora\Auth\ApiKeyScope;
use Spora\Models\Agent;
use Tests\Concerns\CreatesPrincipal;

/**
 * Key minting and verification.
 *
 * The load-bearing property is that the stored row is enough to verify a
 * presented key, and that nothing anywhere can recover the secret from it.
 */
describe('ApiKeyIssuer', function (): void {
    uses(CreatesPrincipal::class);

    /**
     * A fresh user and agent per test.
     *
     * Per test rather than cached: `Pest.php` wraps every test in a
     * transaction it rolls back, so anything cached across tests would name
     * rows that no longer exist.
     */
    function agent(): Agent
    {
        $auth = bootAuthLayer();
        static $seq = 0;
        $seq++;
        $userId = $auth->register("issuer{$seq}@example.com", 'Password1!', "Issuer{$seq}");

        return Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name' => "Issuer {$seq}",
            'max_steps' => 10,
            'is_active' => true,
        ]);
    }

    it('issues a key it can immediately verify', function (): void {
        $issuer = new ApiKeyIssuer();
        [$key, $plaintext] = $issuer->issue(ApiKeyScope::Agent, 'ci', agent()->id);

        expect($plaintext)->toStartWith('spk_' . $key->id . '_');
        expect($issuer->verify($issuer->secretFrom($plaintext), $key->fresh())[0])->toBeTrue();
    });

    it('stores only a digest of the secret', function (): void {
        $issuer = new ApiKeyIssuer();
        [$key, $plaintext] = $issuer->issue(ApiKeyScope::Agent, 'ci', agent()->id);
        $secret = $issuer->secretFrom($plaintext);

        expect($key->secret_hash)->toBe(hash('sha256', $secret));
        // The plaintext is not recoverable from the row: the digest is
        // one-way and nothing else on the row carries it.
        expect($key->secret_hash)->not->toContain($secret);
        expect($plaintext)->not->toContain($key->secret_hash);
    });

    it('rejects a secret that differs by one character', function (): void {
        $issuer = new ApiKeyIssuer();
        [$key, $plaintext] = $issuer->issue(ApiKeyScope::Agent, 'ci', agent()->id);

        $secret = $issuer->secretFrom($plaintext);
        $mutated = substr($secret, 0, -1) . ($secret[-1] === 'A' ? 'B' : 'A');

        expect($issuer->verify($mutated, $key->fresh())[0])->toBeFalse();
    });

    it('fails closed when the row is gone', function (): void {
        $issuer = new ApiKeyIssuer();
        [, $plaintext] = $issuer->issue(ApiKeyScope::Agent, 'ci', agent()->id);

        expect($issuer->verify($issuer->secretFrom($plaintext), null)[0])->toBeFalse();
    });

    it('splits a key into its parts only when the shape is right', function (): void {
        $issuer = new ApiKeyIssuer();
        [, $plaintext] = $issuer->issue(ApiKeyScope::Agent, 'ci', agent()->id);
        $id = $issuer->idFrom($plaintext);
        $secret = $issuer->secretFrom($plaintext);

        expect($id)->toMatch('/^[a-f0-9]{32}$/');
        expect($issuer->compose($id, $secret))->toBe($plaintext);

        foreach ([
            'no prefix' => 'nope_' . $id . '_' . $secret,
            'id one char short' => 'spk_' . substr($id, 0, 31) . '_' . $secret,
            'id one char long' => 'spk_' . $id . 'a' . '_' . $secret,
            'short id' => 'spk_abc_' . $secret,
            'non hex id' => 'spk_' . str_repeat('z', 32) . '_' . $secret,
            'illegal character in secret' => 'spk_' . $id . '_' . $secret . '!',
            'empty secret' => 'spk_' . $id . '_',
            'empty' => '',
        ] as $label => $bad) {
            expect($issuer->idFrom($bad))->toBeNull("{$label} should not yield an id");
            expect($issuer->secretFrom($bad))->toBeNull("{$label} should not yield a secret");
        }
    });

    it('parses a secret that itself contains an underscore', function (): void {
        $issuer = new ApiKeyIssuer();

        // The secret is base64url, whose alphabet includes `_`. A parser that
        // splits on the first underscore reads the wrong boundary here and
        // hands back a secret one character short of the real one, which then
        // fails verification against the digest for no visible reason. Most
        // secrets contain an underscore, so this is the common case.
        $id = str_repeat('a1b2', 8);
        $secret = '_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p';

        expect($issuer->parse($issuer->compose($id, $secret)))->toBe([$id, $secret]);
    });

    it('round-trips every issued key regardless of what the secret contains', function (): void {
        $issuer = new ApiKeyIssuer();
        $agentId = agent()->id;

        // 64 issued keys: enough that a secret containing `_` is all but
        // guaranteed, which is what makes a boundary-splitting bug
        // reproducible rather than intermittent.
        $withUnderscore = 0;

        for ($i = 0; $i < 64; $i++) {
            [$key, $plaintext] = $issuer->issue(ApiKeyScope::Agent, 'rt', $agentId);
            $secret = $issuer->secretFrom($plaintext);

            expect($issuer->idFrom($plaintext))->toBe($key->id);
            expect($issuer->verify($secret, $key->fresh())[0])->toBeTrue();

            if (str_contains((string) $secret, '_')) {
                $withUnderscore++;
            }
        }

        expect($withUnderscore)->toBeGreaterThan(0);
    });

    it('binds the secret to its row rather than to the id alone', function (): void {
        $issuer = new ApiKeyIssuer();
        [$mine, $plaintext] = $issuer->issue(ApiKeyScope::Agent, 'ci', agent()->id);
        [$theirs] = $issuer->issue(ApiKeyScope::Agent, 'other', agent()->id);

        $secret = $issuer->secretFrom($plaintext);

        // Each digest is bound to its own row, so this row's secret does not
        // authenticate against the other row even when the caller names it.
        expect($issuer->verify($secret, $theirs->fresh())[0])->toBeFalse();
        expect($issuer->verify($secret, $mine->fresh())[0])->toBeTrue();
        expect($mine->secret_hash)->not->toBe($theirs->secret_hash);
    });

    it('writes the scope and the target the scope names', function (): void {
        $issuer = new ApiKeyIssuer();

        [$agentKey] = $issuer->issue(ApiKeyScope::Agent, 'a', agent()->id);
        [$globalKey] = $issuer->issue(ApiKeyScope::Global, 'g');

        expect($agentKey->scope)->toBe('agent');
        expect($agentKey->agent_id)->not->toBeNull();
        expect($agentKey->group_id)->toBeNull();
        expect($globalKey->scope)->toBe('global');
        expect($globalKey->agent_id)->toBeNull();
        expect($globalKey->group_id)->toBeNull();
    });

    it('refuses a target the scope does not name', function (): void {
        $issuer = new ApiKeyIssuer();

        // An agent key that names a group, a group key that names an agent,
        // and a global key that names either: each would give its holder a
        // reach the scope does not describe.
        expect(fn() => $issuer->issue(ApiKeyScope::Agent, 'x', null, 7))
            ->toThrow(InvalidArgumentException::class);
        expect(fn() => $issuer->issue(ApiKeyScope::Agent, 'x', 7, 7))
            ->toThrow(InvalidArgumentException::class);
        expect(fn() => $issuer->issue(ApiKeyScope::Group, 'x', 7))
            ->toThrow(InvalidArgumentException::class);
        expect(fn() => $issuer->issue(ApiKeyScope::Global, 'x', 7))
            ->toThrow(InvalidArgumentException::class);
    });
});
