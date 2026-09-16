<?php

declare(strict_types=1);

use Spora\Services\SpeechProviderConfigValidator;
use Spora\Speech\Attributes\AcceptedAudioMime;

/**
 * Walk `#[AcceptedAudioMime]` attributes on a provider class and
 * reflect them into a MediaRecorder-style MIME list. The collector
 * is the single source of truth that
 * {@see \Spora\Speech\SpeechToTextRegistry::describeWithConfig()}
 * surfaces per-row, and the recorder's MIME picker consumes on the
 * frontend.
 */

#[AcceptedAudioMime('audio/wav')]
#[AcceptedAudioMime('audio/mp3')]
final class TwoMimesFixture {}

final class NoAttributesFixture {}

test('collectAcceptedAudioMimes returns declared MIMEs in declaration order', function (): void {
    $mimes = SpeechProviderConfigValidator::collectAcceptedAudioMimes(TwoMimesFixture::class);
    expect($mimes)->toBe(['audio/wav', 'audio/mp3']);
});

test('collectAcceptedAudioMimes returns empty list for class without attributes', function (): void {
    $mimes = SpeechProviderConfigValidator::collectAcceptedAudioMimes(NoAttributesFixture::class);
    expect($mimes)->toBe([]);
});

test('collectAcceptedAudioMimes returns empty list for a non-existent class', function (): void {
    $mimes = SpeechProviderConfigValidator::collectAcceptedAudioMimes('Nonexistent\\Class\\Path');
    expect($mimes)->toBe([]);
});

/**
 * Mirrors the existing `#[ToolSetting]` repeatable-attribute
 * convention so plugin authors can declare many preferred MIMEs
 * (one per line) without an array helper.
 */
#[AcceptedAudioMime('audio/ogg;codecs=opus')]
#[AcceptedAudioMime('audio/mp4')]
#[AcceptedAudioMime('audio/webm;codecs=opus')]
final class MiniMaxShapeFixture {}

test('MiniMax-shape declaration preserves plugin-declared preference order (OGG first, then MP4, then legacy WebM)', function (): void {
    // Mirrors the order MiniMaxTranscribeProvider ships in
    // spora-plugin-minimax — OGG over Opus first (Chrome 105+ +
    // Firefox, both natively supported by MiniMax), then Safari's
    // MP4/AAC fallback, then the WebM fallback for browsers that
    // don't do OGG recording.
    $mimes = SpeechProviderConfigValidator::collectAcceptedAudioMimes(MiniMaxShapeFixture::class);
    expect($mimes)->toBe([
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/webm;codecs=opus',
    ]);
});
