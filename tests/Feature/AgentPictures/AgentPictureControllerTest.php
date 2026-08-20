<?php

declare(strict_types=1);

namespace Tests\Feature\AgentPictures;

use RuntimeException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\AgentPictureController;
use Spora\Models\Agent;
use Spora\Models\AgentPicture;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentPictures\Archetype;
use Spora\Services\AgentPictures\Palette;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MimeSniffer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coverage tests for AgentPictureController that exercise the validation
 * paths (404, 400, 413) without going through the full MediaArchiveService.
 * The full upload happy path is covered in tests/Feature/MediaArchive/.
 */
beforeEach(function (): void {
    $userId = bootAuth(bootAuthLayer());
    // Seed an agent for tests that exercise it; tests that don't need an
    // agent just ignore the seed.
    \Illuminate\Database\Capsule\Manager::table('agents')->insert([
        'id' => 1, 'principal_id' => $this->createUserPrincipal($userId), 'name' => 'Test', 'max_steps' => 10,
        'is_active' => 1, 'allow_followup' => 1, 'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
});

function setupStorage(): string
{
    $tmp = sys_get_temp_dir() . '/spora-agent-picture-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    return $tmp;
}

function teardownStorage(string $tmp): void
{
    putenv('SPORA_STORAGE_DIR');
    unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
    if (is_dir($tmp)) {
        foreach (glob($tmp . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($tmp);
    }
}

function buildController(AgentPictureService $service): AgentPictureController
{
    $agentService = new class implements AgentServiceInterface {
        public function getAgentsForUser(int $userId): array
        {
            return [];
        }
        public function createAgent(int $userId, array $data, ?int $principalId = null): Agent
        {
            throw new RuntimeException('not used');
        }
        public function getAgent(int $agentId, int $userId): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function updateAgent(int $agentId, int $userId, array $data): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function updateAgentByAgentId(int $agentId, array $data): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function getAgentByAgentId(int $agentId): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function deleteAgent(int $agentId, int $userId): bool
        {
            return true;
        }
        public function setPinned(int $userId, int $agentId, bool $pinned): Agent
        {
            return Agent::query()->find($agentId) ?? throw new RuntimeException('not found');
        }
        public function setArchived(int $userId, int $agentId, bool $archived): Agent
        {
            return Agent::query()->find($agentId) ?? throw new RuntimeException('not found');
        }
        public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): Agent
        {
            throw new RuntimeException('not used');
        }
    };
    $toolSettings = new class implements AgentToolSettingsServiceInterface {
        /** @phpstan-ignore return.unusedType */
        public function enableTool(int $agentId, int $userId, string $toolClass): array
        {
            return ['tool' => []];
        }
        public function disableTool(int $agentId, int $userId, string $toolClass): void {}
        public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
        {
            return null;
        }
        public function getAllToolsStatus(int $agentId, int $userId): ?array
        {
            return null;
        }
        public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
        {
            return [];
        }
        public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
        {
            return [];
        }
        public function deleteOverride(int $agentId, int $userId, string $toolClass): void {}
        public function getToolsOperations(int $agentId, int $userId): ?array
        {
            return null;
        }
        /** @phpstan-ignore return.unusedType */
        public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
        {
            return [
                'operation' => $operation,
                'tool_class' => $toolClass,
                'enabled' => null,
                'default_requires_approval' => null,
                'effective_enabled' => false,
                'effective_requires_approval' => false,
            ];
        }
        public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
        {
            return [];
        }
    };

    $tmp = setupStorage();
    $paths = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $dataUrl = new DataUrlAssetStore(50 * 1024 * 1024);
    $local = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $mediaArchive = \Tests\Support\MediaArchiveTestSupport::buildService($assetStore);

    return new AgentPictureController(
        bootAuthLayer(),
        $agentService,
        $service,
        $mediaArchive,
        new MimeSniffer(),
    );
}

test('DELETE /agents/{id}/picture/image returns 404 when the agent does not exist', function (): void {
    $req = Request::create('/api/v1/agents/99/picture/image', 'DELETE');
    $req->attributes->set('id', 99);
    $resp = buildController(new AgentPictureService())->deleteImage($req);
    expect($resp->getStatusCode())->toBe(404);
});

test('DELETE /agents/{id}/picture/image returns 200 and preserves the previous avatar', function (): void {
    $service = new AgentPictureService();
    $service->updateAvatar(1, 'researcher', 'v1', 'violet');

    $req = Request::create('/api/v1/agents/1/picture/image', 'DELETE');
    $req->attributes->set('id', 1);
    $resp = buildController($service)->deleteImage($req);

    expect($resp->getStatusCode())->toBe(200);
    $picture = AgentPicture::where('agent_id', 1)->first();
    // detach() restores the operator's prior avatar selection instead of
    // resetting to the hard-coded defaults — see AgentPictureService::detachImage().
    expect($picture->archetype)->toBe('researcher');
    expect($picture->palette_key)->toBe('violet');
});

test('DELETE /agents/{id}/picture/image falls back to defaults when no avatar was set', function (): void {
    $service = new AgentPictureService();
    // No prior updateAvatar — getOrCreate seeds the defaults.

    $req = Request::create('/api/v1/agents/1/picture/image', 'DELETE');
    $req->attributes->set('id', 1);
    $resp = buildController($service)->deleteImage($req);

    expect($resp->getStatusCode())->toBe(200);
    $picture = AgentPicture::where('agent_id', 1)->first();
    expect($picture->archetype)->toBe('assistant');
    expect($picture->palette_key)->toBe('slate');
});

test('POST /agents/{id}/picture/image returns 404 when the agent does not exist', function (): void {
    $req = Request::create('/api/v1/agents/99/picture/image', 'POST');
    $req->attributes->set('id', 99);
    $resp = buildController(new AgentPictureService())->uploadImage($req);
    expect($resp->getStatusCode())->toBe(404);
});

test('POST /agents/{id}/picture/image returns 400 when no file is uploaded', function (): void {
    $req = Request::create('/api/v1/agents/1/picture/image', 'POST');
    $req->attributes->set('id', 1);
    $resp = buildController(new AgentPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('BAD_REQUEST');
});

test('POST /agents/{id}/picture/image returns 413 when the file is too large', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-avatar-big');
    file_put_contents($tmp, str_repeat('A', 2 * 1024 * 1024));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create('/api/v1/agents/1/picture/image', 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', 1);

    $resp = buildController(new AgentPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(413);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('PAYLOAD_TOO_LARGE');
});

// 1x1 valid PNG (smallest possible image — 67 bytes)
const VALID_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

test('POST /agents/{id}/picture/image accepts a valid PNG and persists the agent_pictures row', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-avatar-real');
    file_put_contents($tmp, base64_decode(VALID_PNG_BASE64));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create('/api/v1/agents/1/picture/image', 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', 1);

    $resp = buildController(new AgentPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(201);
    $picture = AgentPicture::where('agent_id', 1)->first();
    expect($picture)->not->toBeNull();
    expect($picture->media_asset_id)->not->toBeNull();
    // upload preserves the avatar metadata so a follow-up DELETE
    // restores the operator's prior archetype/palette choice.
    expect($picture->archetype)->toBe(Archetype::Assistant->value);
    expect($picture->palette_key)->toBe(Palette::Slate->value);
});

test('POST /agents/{id}/picture/image rejects a PDF body even when the client says image/png', function (): void {
    // Bytes look like a PDF (start with %PDF-). The client header lies
    // and claims image/png. Must be rejected by the byte sniffer +
    // image decoder.
    $tmp = tempnam(sys_get_temp_dir(), 'spora-avatar-pdf');
    file_put_contents($tmp, '%PDF-1.4' . str_repeat("\x00", 256));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create('/api/v1/agents/1/picture/image', 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', 1);

    $resp = buildController(new AgentPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(415);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('UNSUPPORTED_MEDIA_TYPE');
    expect(AgentPicture::where('agent_id', 1)->first())->toBeNull();
});

test('POST /agents/{id}/picture/image rejects plain text even when the LLM does not support images', function (): void {
    // The agent does not have an LLM driver_config, so the legacy
    // MediaAllowedTypesService would have rejected image types entirely
    // while still accepting text. The new avatar-only validator must
    // still reject text — avatars are always raster, regardless of LLM
    // capability.
    $tmp = tempnam(sys_get_temp_dir(), 'spora-avatar-text');
    file_put_contents($tmp, "Hello, this is plain text.\n" . str_repeat('a', 64));
    $uploaded = new UploadedFile($tmp, 'avatar.txt', 'text/plain', null, true);
    $req = Request::create('/api/v1/agents/1/picture/image', 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', 1);

    $resp = buildController(new AgentPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(415);
    expect(AgentPicture::where('agent_id', 1)->first())->toBeNull();
});

test('POST /agents/{id}/picture/image accepts a valid WebP with the RIFF + WEBP markers', function (): void {
    // 1x1 valid WebP (smallest possible — RIFF container with VP8 chunk).
    // bytes (hex):
    // 52 49 46 46 (=RIFF)
    // 1a 00 00 00 (=26 bytes follow)
    // 57 45 42 50 (=WEBP)
    // 56 50 38 4c (=VP8L)
    // 0d 00 00 00 (=13 bytes)
    // 2f 00 00 00 00 00 80 3f 00 00 00 00 00
    $bytes = "RIFF\x1a\x00\x00\x00WEBPVP8L\x0d\x00\x00\x00\x2f\x00\x00\x00\x00\x00\x80\x3f\x00\x00\x00\x00\x00";
    $tmp = tempnam(sys_get_temp_dir(), 'spora-avatar-webp');
    file_put_contents($tmp, $bytes);
    $uploaded = new UploadedFile($tmp, 'avatar.webp', 'image/webp', null, true);
    $req = Request::create('/api/v1/agents/1/picture/image', 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', 1);

    $resp = buildController(new AgentPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(201);
});
