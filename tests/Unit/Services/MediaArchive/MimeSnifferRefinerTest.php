<?php

declare(strict_types=1);

use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use Spora\Services\MediaArchive\MimeSniffer;
use Tests\Support\FakeMimeRefiner;
use Tests\Support\ThrowingMimeRefiner;

/**
 * The refiner pass at the tail of {@see MimeSniffer::sniffFromBytes()}.
 *
 * `MediaUploadController::checkMimeAllowed()` gates an upload on the
 * *sniffed* MIME before the converter registry is ever consulted, so a
 * host whose libmagic reports OOXML as `application/zip` rejects a DOCX
 * outright. These tests pin the seam that fixes it: what a refiner is
 * handed, what its return value does to the verdict, and where in the
 * pipeline it sits.
 *
 * The registry is a process-global static, so both hooks reset it: in a
 * parallel run this file's "no refiner registered" assertion would
 * otherwise be decided by whichever test happened to register one first
 * in the same worker.
 */
const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

/** Bytes libmagic reports as a bare zip — the mis-sniff these tests simulate. */
function zipPayload(): string
{
    return "PK\x03\x04" . str_repeat("\x00", 4092);
}

beforeEach(function (): void {
    MediaMimeRefinerDiscovery::reset();
    FakeMimeRefiner::reset();
});

afterEach(function (): void {
    MediaMimeRefinerDiscovery::reset();
    FakeMimeRefiner::reset();
});

test('with no refiner registered the sniffed MIME is returned untouched', function (): void {
    $bytes = zipPayload();

    // Guard the premise: the fixture really does sniff as a bare zip on
    // this host, otherwise the "untouched" assertion proves nothing.
    expect((new MimeSniffer())->sniffFromBytes($bytes))->toBe('application/zip');
    expect(MediaMimeRefinerDiscovery::all())->toBe([]);
});

test('a refiner upgrades a coarse application/zip sniff to its returned MIME', function (): void {
    FakeMimeRefiner::respondWith(DOCX_MIME);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    expect((new MimeSniffer())->sniffFromBytes(zipPayload(), 'report.docx'))->toBe(DOCX_MIME);
});

test('the refiner receives the full byte string, not the 4 KiB sniffer prefix', function (): void {
    FakeMimeRefiner::respondWith(DOCX_MIME);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    $bytes = zipPayload() . str_repeat("\x00", 200_000);
    (new MimeSniffer())->sniffFromBytes($bytes, 'report.docx');

    expect(FakeMimeRefiner::$calls)->toHaveCount(1);
    expect(FakeMimeRefiner::$calls[0]['bytes'])->toBe($bytes);
});

test('the refiner receives the filename and the pre-refinement MIME', function (): void {
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    (new MimeSniffer())->sniffFromBytes(zipPayload(), 'quarterly report.docx');

    expect(FakeMimeRefiner::$calls[0]['filename'])->toBe('quarterly report.docx');
    expect(FakeMimeRefiner::$calls[0]['sniffedMime'])->toBe('application/zip');
});

test('a null filename is passed through rather than defaulted', function (): void {
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    (new MimeSniffer())->sniffFromBytes(zipPayload());

    expect(FakeMimeRefiner::$calls[0]['filename'])->toBeNull();
});

test('a declining refiner falls through to the next registered refiner', function (): void {
    $accepting = new class implements MediaMimeRefinerInterface {
        public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string
        {
            return $sniffedMime === 'application/zip' ? 'application/x-second-refiner' : null;
        }
    };

    // Registration order is the resolution order, and `null` means "I
    // have nothing to add", not "keep the previous answer".
    FakeMimeRefiner::respondWith(null);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);
    MediaMimeRefinerDiscovery::add($accepting::class);

    expect((new MimeSniffer())->sniffFromBytes(zipPayload(), 'report.docx'))
        ->toBe('application/x-second-refiner');
    expect(FakeMimeRefiner::$calls)->toHaveCount(1);
});

test('a refiner that declines every step leaves the sniffed MIME alone', function (): void {
    FakeMimeRefiner::respondWith(null);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    expect((new MimeSniffer())->sniffFromBytes(zipPayload(), 'report.docx'))->toBe('application/zip');
});

test('refiners run after the built-in Typst text/plain upgrade', function (): void {
    // Ordering constraint: a refiner must see the most specific MIME core
    // can produce, otherwise it has to re-derive the Typst rule itself.
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    (new MimeSniffer())->sniffFromBytes('= Hello' . "\n", 'source.typ');

    expect(FakeMimeRefiner::$calls[0]['sniffedMime'])->toBe('text/x-typst');
});

test('a refiner can further upgrade the Typst MIME', function (): void {
    FakeMimeRefiner::respondWith('text/x-typst-v2');
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    expect((new MimeSniffer())->sniffFromBytes('= Hello' . "\n", 'source.typ'))->toBe('text/x-typst-v2');
});

test('refiners also see the magic-table verdict, ahead of finfo', function (): void {
    // The refiner pass sits after `sniffPrefix()`, so a registered refiner
    // is the last word on every path, not just on finfo's misses.
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    (new MimeSniffer())->sniffFromBytes('%PDF-1.4' . str_repeat("\x00", 32), 'doc.pdf');

    expect(FakeMimeRefiner::$calls[0]['sniffedMime'])->toBe('application/pdf');
});

test('empty input short-circuits before any refiner runs', function (): void {
    // Nothing to refine — a refiner is handed bytes by contract, and
    // there are none.
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    expect((new MimeSniffer())->sniffFromBytes(''))->toBe(MimeSniffer::OCTET_STREAM);
    expect(FakeMimeRefiner::$calls)->toBe([]);
});

test('sniffFromExtension maps docx to the OOXML MIME', function (): void {
    // The extension map and MediaArchiveService's two static maps must
    // agree, or the URL branch and the byte branch disagree about what
    // a `.docx` is.
    expect((new MimeSniffer())->sniffFromExtension('report.docx'))->toBe(DOCX_MIME);
    expect(MediaArchiveService::mimeForExtension('docx'))->toBe(DOCX_MIME);
    expect(MediaArchiveService::extensionForMime(DOCX_MIME))->toBe('docx');
});

test('a refiner that throws is declined rather than propagated', function (): void {
    MediaMimeRefinerDiscovery::add(ThrowingMimeRefiner::class);

    // The exception would otherwise travel sniffFromBytes() →
    // ingestFromBytes() → the upload controller and fail every media
    // upload in the process over one plugin's bug. The sniffed verdict
    // has to survive it.
    expect((new MimeSniffer())->sniffFromBytes(zipPayload(), 'report.docx'))->toBe('application/zip');
});

test('a throwing refiner does not stop the refiners registered after it', function (): void {
    MediaMimeRefinerDiscovery::add(ThrowingMimeRefiner::class);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);
    FakeMimeRefiner::respondWith(DOCX_MIME);

    expect((new MimeSniffer())->sniffFromBytes(zipPayload(), 'report.docx'))->toBe(DOCX_MIME);
    expect(FakeMimeRefiner::$calls)->toHaveCount(1);
});

test('a throwing refiner with no logger installed still declines quietly', function (): void {
    // The logger is optional so `new MimeSniffer()` stays valid at the
    // plain call sites; a null logger may only cost the diagnostic, never
    // the behaviour.
    MediaMimeRefinerDiscovery::add(ThrowingMimeRefiner::class);

    expect((new MimeSniffer())->sniffFromBytes(zipPayload(), 'report.docx'))->toBe('application/zip');
});
