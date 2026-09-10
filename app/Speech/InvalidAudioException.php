<?php

declare(strict_types=1);

namespace Spora\Speech;

/**
 * Raised by {@see SpeechToTextProviderInterface::transcribe()} when the
 * provider cannot ingest the supplied MIME type (e.g. video, an exotic
 * container, an empty/garbled payload).
 *
 * Distinct from {@see SpeechToTextException} so the controller can map
 * it to HTTP 422 `INVALID_AUDIO` (client-side fix) instead of HTTP 502
 * (provider-side failure).
 *
 * Subclassing {@see SpeechToTextException} keeps the catch order simple:
 * the controller catches `InvalidAudioException` first, then the broader
 * `SpeechToTextException`.
 */
final class InvalidAudioException extends SpeechToTextException {}
