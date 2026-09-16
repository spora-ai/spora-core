<?php

declare(strict_types=1);

use Spora\Services\SpeechProviderConfigValidator;
use Spora\Speech\Attributes\AcceptedAudioMime;

/** `#[AcceptedAudioMime]` collector + declaration-order contract. */

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

// Repeatable, mirrors the `#[ToolSetting]` convention so plugin authors
// can declare many preferred MIMEs (one per line) without an array helper.
#[AcceptedAudioMime('audio/ogg;codecs=opus')]
#[AcceptedAudioMime('audio/mp4')]
#[AcceptedAudioMime('audio/webm;codecs=opus')]
final class MiniMaxShapeFixture {}

test('MiniMax-shape declaration preserves plugin-declared preference order (OGG first, then MP4, then legacy WebM)', function (): void {
    $mimes = SpeechProviderConfigValidator::collectAcceptedAudioMimes(MiniMaxShapeFixture::class);
    expect($mimes)->toBe([
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/webm;codecs=opus',
    ]);
});
