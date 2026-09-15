<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Tests\Support\FakeDerivativeProducer;

/**
 * End-to-end coverage for the derivative surface `MediaTool` exposes
 * to the LLM:
 *
 *   - `get_media`     — surfaces a `derivatives[]` array on every parent
 *                       and a `parent_id` on every derivative.
 *   - `list_derivatives` — enumerates the parent's derivatives with the
 *                          same shape the operator dashboard renders,
 *                          and narrows on an optional `format` filter.
 *   - `create_derivative` — generates a fresh derivative via the
 *                          registered producer; idempotent on the
 *                          natural key; fails cleanly when no producer
 *                          supports the format/source pair.
 *
 * Tests run with admin auth so the scope gate is bypassed — the LLM
 * scope check is covered by `MediaToolScopeTest` and the orchestrator
 * wiring tests.
 *
 * Producer registration uses the static
 * {@see MediaDerivativeProducerDiscovery} registry, which is shared
 * across the whole process; each test resets it in `afterEach` so
 * parallel runners don't see stale registrations from sibling tests.
 */

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * Seed a parent `media_assets` row with bytes the registered
 * `FakeDerivativeProducer` recognises (its declared source format is
 * `image/png`). The producer doesn't actually inspect the bytes for
 * image validity — `produce()` just emits a deterministic `%PDF-…`
 * string — so the bytes only need to be a non-empty payload to make
 * `data_url` storage_mode a real BLOB column read.
 */
function seedMediaToolDerivativeParent(string $id, ?int $agentId = null): MediaAsset
{
    $agentId ??= seedMediaToolAgent();
    $asset = seedMediaAsset(
        agentId: $agentId,
        userId: 99,
        mime: 'image/png',
        idOverride: $id,
    );
    $asset->byte_size = 32;
    $asset->payload   = str_repeat("\x89PNG", 8);
    $asset->save();
    return $asset;
}

/**
 * Build a MediaTool wired against the same producer registration
 * this file uses. The default `FakeDerivativeProducer` is registered
 * up-front so `create_derivative` has a producer to find.
 *
 * Returns `[$tool, $restore]`. The restore closure wipes the producer
 * registry so parallel workers don't leak state.
 */
function makeMediaToolForDerivatives(?Spora\Auth\AuthService $auth = null): array
{
    // `makeMediaToolWithRealArchive()` returns an associative array;
    // PHP's positional destructuring only works on numeric-keyed
    // arrays, so destructure by key here to keep `$tool` populated.
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive($auth ?? makeMediaToolAdminAuth());
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);
    return [$tool, $restore];
}

/**
 * Insert a `media_derivatives` row directly. Bypasses the producer
 * pipeline so the read-side tests can pin the wire shape without
 * caring which producer (and which mime) the test happens to have
 * registered.
 */
function seedMediaDerivativeLink(string $parentId, string $derivativeId, string $format, string $plugin, string $operation): void
{
    Capsule::table('media_derivatives')->insert([
        'id'                 => sprintf('%08x-aaaa-bbbb-cccc-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)),
        'parent_id'          => $parentId,
        'derivative_id'      => $derivativeId,
        'format'             => $format,
        'producer_plugin'    => $plugin,
        'producer_operation' => $operation,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);
}

describe('MediaTool::get_media derivative enrichment', function (): void {
    it('returns an empty derivatives[] array and a null parent_id for a vanilla asset', function (): void {
        seedMediaToolDerivativeParent('11111111-aaaa-bbbb-cccc-111111111111');

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => '11111111-aaaa-bbbb-cccc-111111111111'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['derivatives'])->toBe([]);
            expect($result->data['parent_id'])->toBeNull();
        } finally {
            $restore();
        }
    });

    it('populates derivatives[] with one row per child of a parent asset', function (): void {
        $parent = seedMediaToolDerivativeParent('22222222-aaaa-bbbb-cccc-222222222222');
        $childAId = '22222222-aaaa-bbbb-cccc-aaaaaaaaaaaa';
        $childBId = '22222222-aaaa-bbbb-cccc-bbbbbbbbbbbb';
        foreach ([$childAId, $childBId] as $idx => $childId) {
            $child = seedMediaAsset(
                agentId: null,
                userId: 99,
                mime: 'application/pdf',
                idOverride: $childId,
            );
            $child->byte_size = 1024;
            $child->payload   = '%PDF-fake-' . $childId;
            $child->plugin_slug = 'fake-derivative-producer';
            $child->tool_name   = 'render';
            $child->save();
            seedMediaDerivativeLink($parent->id, $child->id, $idx === 0 ? 'pdf' : 'txt', 'fake-derivative-producer', 'render');
        }

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => '22222222-aaaa-bbbb-cccc-222222222222'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['parent_id'])->toBeNull();

            $derivatives = $result->data['derivatives'];
            expect($derivatives)->toHaveCount(2);
            expect($derivatives[0]['format'])->toBe('pdf');
            expect($derivatives[0]['media_id'])->toBe($childAId);
            expect($derivatives[0]['asset_url'])->toContain($childAId);
            expect($derivatives[0]['producer_plugin'])->toBe('fake-derivative-producer');
            expect($derivatives[0]['producer_operation'])->toBe('render');
            expect($derivatives[0])->toHaveKey('label');
            expect($derivatives[1]['format'])->toBe('txt');
        } finally {
            $restore();
        }
    });

    it('populates parent_id when the asset is itself a derivative', function (): void {
        $parentId = '44444444-aaaa-bbbb-cccc-444444444444';
        $childId  = '44444444-aaaa-bbbb-cccc-444444444445';
        seedMediaToolDerivativeParent($parentId);
        $child = seedMediaAsset(
            agentId: null,
            userId: 99,
            mime: 'application/pdf',
            idOverride: $childId,
        );
        $child->byte_size = 1024;
        $child->payload   = '%PDF-fake';
        $child->save();
        seedMediaDerivativeLink($parentId, $childId, 'pdf', 'fake-derivative-producer', 'render');

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $childId],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['parent_id'])->toBe($parentId);
            // The derivative itself isn't a parent — empty list is correct.
            expect($result->data['derivatives'])->toBe([]);
        } finally {
            $restore();
        }
    });
});

describe('MediaTool::list_derivatives', function (): void {
    it('returns an empty list with a header when the parent has no derivatives', function (): void {
        seedMediaToolDerivativeParent('55555555-aaaa-bbbb-cccc-555555555555');

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                ['action' => 'list_derivatives', 'asset_id' => '55555555-aaaa-bbbb-cccc-555555555555'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toContain('0 derivative(s)');
            expect($result->data['parent_id'])->toBe('55555555-aaaa-bbbb-cccc-555555555555');
            expect($result->data['derivatives'])->toBe([]);
            expect($result->data['count'])->toBe(0);
            expect($result->data['format'])->toBeNull();
        } finally {
            $restore();
        }
    });

    it('returns every derivative of a parent in the same wire shape the operator dashboard renders', function (): void {
        $parent = seedMediaToolDerivativeParent('66666666-aaaa-bbbb-cccc-666666666666');
        $childAId = '66666666-aaaa-bbbb-cccc-aaaaaaaaaaaa';
        $childBId = '66666666-aaaa-bbbb-cccc-bbbbbbbbbbbb';
        foreach ([[$childAId, 'pdf'], [$childBId, 'txt']] as [$childId, $format]) {
            $child = seedMediaAsset(
                agentId: null,
                userId: 99,
                mime: 'application/pdf',
                idOverride: $childId,
            );
            $child->byte_size = 1024;
            $child->payload   = '%PDF-fake-' . $childId;
            $child->save();
            seedMediaDerivativeLink($parent->id, $child->id, $format, 'fake-derivative-producer', 'render');
        }

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                ['action' => 'list_derivatives', 'asset_id' => '66666666-aaaa-bbbb-cccc-666666666666'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toContain('2 derivative(s)');
            expect($result->data['count'])->toBe(2);
            $rows = $result->data['derivatives'];
            expect($rows)->toHaveCount(2);
            $formats = array_map(static fn(array $row): string => (string) $row['format'], $rows);
            expect($formats)->toBe(['pdf', 'txt']);
            // Every row carries the operator-visible fields.
            foreach ($rows as $row) {
                expect($row)->toHaveKeys(['media_id', 'format', 'asset_url', 'label', 'producer_plugin', 'producer_operation', 'created_at']);
            }
        } finally {
            $restore();
        }
    });

    it('narrows to one derivative when the optional format filter is supplied', function (): void {
        $parent = seedMediaToolDerivativeParent('88888888-aaaa-bbbb-cccc-888888888888');
        $childAId = '88888888-aaaa-bbbb-cccc-aaaaaaaaaaaa';
        $childBId = '88888888-aaaa-bbbb-cccc-bbbbbbbbbbbb';
        foreach ([[$childAId, 'pdf'], [$childBId, 'txt']] as [$childId, $format]) {
            $child = seedMediaAsset(
                agentId: null,
                userId: 99,
                mime: 'application/pdf',
                idOverride: $childId,
            );
            $child->byte_size = 1024;
            $child->payload   = '%PDF-fake-' . $childId;
            $child->save();
            seedMediaDerivativeLink($parent->id, $child->id, $format, 'fake-derivative-producer', 'render');
        }

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                ['action' => 'list_derivatives', 'asset_id' => '88888888-aaaa-bbbb-cccc-888888888888', 'format' => 'PDF'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['count'])->toBe(1);
            expect($result->data['format'])->toBe('pdf');
            expect($result->data['derivatives'][0]['format'])->toBe('pdf');
            expect($result->data['derivatives'][0]['media_id'])->toBe($childAId);
        } finally {
            $restore();
        }
    });

    it('returns asset-not-found when the parent asset_id is unknown', function (): void {
        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                ['action' => 'list_derivatives', 'asset_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toBe('Media asset not found.');
        } finally {
            $restore();
        }
    });

    it('describes the action with the format filter when present', function (): void {
        [$tool] = makeMediaToolForDerivatives();

        expect($tool->describeAction([
            'action'   => 'list_derivatives',
            'asset_id' => 'foo',
            'format'   => 'png',
        ]))->toBe('Media list_derivatives(foo, format=png)');

        expect($tool->describeAction([
            'action'   => 'list_derivatives',
            'asset_id' => 'foo',
        ]))->toBe('Media list_derivatives(foo)');
    });
});

describe('MediaTool::create_derivative', function (): void {
    it('produces a fresh derivative via the registered producer and returns its id + producer attribution', function (): void {
        seedMediaToolDerivativeParent('aaaaaaaa-1111-2222-3333-aaaaaaaaaaaa');

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                [
                    'action'   => 'create_derivative',
                    'asset_id' => 'aaaaaaaa-1111-2222-3333-aaaaaaaaaaaa',
                    'format'   => 'pdf',
                ],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toContain('Created derivative');
            expect($result->data['parent_id'])->toBe('aaaaaaaa-1111-2222-3333-aaaaaaaaaaaa');
            expect($result->data['format'])->toBe('pdf');
            expect($result->data['mime_type'])->toBe('application/pdf');
            expect($result->data['plugin_slug'])->toBe('fake-derivative-producer');
            expect($result->data['tool_name'])->toBe('render');
            expect($result->data['asset_url'])->toContain($result->data['derivative_id']);

            // The new derivative is linked to the parent.
            $derivativeId = $result->data['derivative_id'];
            $link = Capsule::table('media_derivatives')
                ->where('derivative_id', $derivativeId)
                ->where('parent_id', 'aaaaaaaa-1111-2222-3333-aaaaaaaaaaaa')
                ->first();
            expect($link)->not->toBeNull();
            expect((string) $link->format)->toBe('pdf');
            expect((string) $link->producer_plugin)->toBe('fake-derivative-producer');
        } finally {
            $restore();
        }
    });

    it('is idempotent on the natural key (re-rendering returns the same derivative id)', function (): void {
        seedMediaToolDerivativeParent('bbbbbbbb-1111-2222-3333-bbbbbbbbbbbb');

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $first = $tool->execute(
                [
                    'action'   => 'create_derivative',
                    'asset_id' => 'bbbbbbbb-1111-2222-3333-bbbbbbbbbbbb',
                    'format'   => 'pdf',
                ],
                agentId: 1,
                userId: 99,
            );
            $second = $tool->execute(
                [
                    'action'   => 'create_derivative',
                    'asset_id' => 'bbbbbbbb-1111-2222-3333-bbbbbbbbbbbb',
                    'format'   => 'pdf',
                ],
                agentId: 1,
                userId: 99,
            );

            expect($first->success)->toBeTrue();
            expect($second->success)->toBeTrue();
            expect($first->data['derivative_id'])->toBe($second->data['derivative_id']);

            // Exactly one join row, not two.
            $count = Capsule::table('media_derivatives')
                ->where('parent_id', 'bbbbbbbb-1111-2222-3333-bbbbbbbbbbbb')
                ->count();
            expect($count)->toBe(1);
        } finally {
            $restore();
        }
    });

    it('fails with a hint pointing at list_derivatives when no producer supports the format/source pair', function (): void {
        seedMediaToolDerivativeParent('cccccccc-1111-2222-3333-cccccccccccc');

        [$tool, $restore] = makeMediaToolForDerivatives();
        // Override the helper's auto-registration so this case actually
        // has no producers.
        MediaDerivativeProducerDiscovery::reset();
        try {
            $result = $tool->execute(
                [
                    'action'   => 'create_derivative',
                    'asset_id' => 'cccccccc-1111-2222-3333-cccccccccccc',
                    'format'   => 'pdf',
                ],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('No derivative producer supports format "pdf"');
            expect($result->content)->toContain('list_derivatives');
        } finally {
            $restore();
        }
    });

    it('fails when `format` is omitted', function (): void {
        seedMediaToolDerivativeParent('dddddddd-1111-2222-3333-dddddddddddd');

        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                [
                    'action'   => 'create_derivative',
                    'asset_id' => 'dddddddd-1111-2222-3333-dddddddddddd',
                ],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('`format` is required');
            expect($result->content)->toContain('list_derivatives');
        } finally {
            $restore();
        }
    });

    it('returns asset-not-found when the parent asset_id is unknown', function (): void {
        [$tool, $restore] = makeMediaToolForDerivatives();
        try {
            $result = $tool->execute(
                [
                    'action'   => 'create_derivative',
                    'asset_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
                    'format'   => 'pdf',
                ],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toBe('Media asset not found.');
        } finally {
            $restore();
        }
    });

    it('exposes create_derivative + list_derivatives in the tool schema with the right required[] narrowings', function (): void {
        $tool = buildMediaToolForSchema();
        $schema = $tool->getParametersSchema();
        $enum = $schema['properties']['action']['enum'];
        expect($enum)->toContain('create_derivative');
        expect($enum)->toContain('list_derivatives');

        // `format` is required only when `create_derivative` is in the
        // allowed-ops set; `list_derivatives` keeps it optional.
        $filteredCreate = Spora\Tools\Schema\OperationSchemaFilter::filter(
            $schema,
            ['create_derivative'],
            'action',
        );
        expect($filteredCreate['required'])->toContain('asset_id', 'format');

        $filteredList = Spora\Tools\Schema\OperationSchemaFilter::filter(
            $schema,
            ['list_derivatives'],
            'action',
        );
        expect($filteredList['required'])->toContain('asset_id');
        expect($filteredList['required'])->not->toContain('format');
    });
});

describe('MediaTool::search derivative filtering', function (): void {
    it('still hides derivative rows from the listing', function (): void {
        $parent = seedMediaToolDerivativeParent('abcdef00-aaaa-bbbb-cccc-000000000000');
        $child = seedMediaAsset(
            agentId: null,
            userId: 99,
            mime: 'application/pdf',
            idOverride: 'abcdef00-aaaa-bbbb-cccc-111111111111',
        );
        $child->byte_size = 1024;
        $child->payload   = '%PDF-fake';
        $child->save();
        seedMediaDerivativeLink($parent->id, $child->id, 'pdf', 'fake-derivative-producer', 'render');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'search', 'limit' => 100],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            $ids = array_map(static fn(array $row): string => (string) $row['id'], $result->data['items']);
            expect($ids)->toContain($parent->id);
            expect($ids)->not->toContain($child->id);
        } finally {
            $restore();
        }
    });
});
