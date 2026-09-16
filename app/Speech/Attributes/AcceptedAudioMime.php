<?php

declare(strict_types=1);

namespace Spora\Speech\Attributes;

use Attribute;

/**
 * Declares an audio MIME the {@see \Spora\Speech\SpeechToTextProviderInterface}
 * implementation accepts on its `transcribe()` call.
 *
 * Plugin authors add one attribute per supported MIME, in preference
 * order. The first matching `MediaRecorder.isTypeSupported()` probe the
 * browser reports wins — declaring `audio/ogg;codecs=opus` ahead of
 * `audio/webm;codecs=opus` steers Chrome 105+ off the Matroska container
 * (MiniMax rejects "matroska,webm" even though the underlying Opus
 * codec is identical to OGG-wrapped Opus) without any server-side
 * transcoding.
 *
 * Collected by {@see \Spora\Services\SpeechProviderConfigValidator::collectAcceptedAudioMimes()}
 * and surfaced per-row on `GET /api/v1/speech/capability` as
 * `preferred_audio_mimes: list<string>`.
 *
 * Examples
 * --------
 *
 * MiniMax-style preference (OGG first, then MP4, then legacy WebM):
 *
 *   #[AcceptedAudioMime('audio/ogg;codecs=opus')]
 *   #[AcceptedAudioMime('audio/mp4')]
 *   #[AcceptedAudioMime('audio/webm;codecs=opus')]
 *   final class MiniMaxTranscribeProvider implements SpeechToTextProviderInterface {}
 *
 * OpenAI-compatible-style preference (WebM first; OpenAI/Mistral/Groq
 * all accept the W3C "audio-only" WebM container quirk):
 *
 *   #[AcceptedAudioMime('audio/webm;codecs=opus')]
 *   #[AcceptedAudioMime('audio/ogg;codecs=opus')]
 *   #[AcceptedAudioMime('audio/mp4')]
 *   #[AcceptedAudioMime('audio/wav')]
 *   final class OpenAiCompatibleTranscriber implements SpeechToTextProviderInterface {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AcceptedAudioMime
{
    /** @param string $mime MediaRecorder-style MIME hint, e.g. `audio/ogg;codecs=opus`, `audio/mp4`, `audio/wav`. */
    public function __construct(
        public readonly string $mime,
    ) {}
}
