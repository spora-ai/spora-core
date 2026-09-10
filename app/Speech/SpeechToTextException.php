<?php

declare(strict_types=1);

namespace Spora\Speech;

use RuntimeException;

/**
 * Raised by {@see SpeechToTextProviderInterface::transcribe()} when the
 * provider call fails (network, auth, rate-limit, malformed response).
 *
 * The transcribe controller catches this and maps it to HTTP 502
 * `SPEECH_PROVIDER_FAILED` with a sanitised message.
 *
 * **Providers MUST NOT include API keys in the exception message.** The
 * controller logs the full message server-side but only forwards the
 * sanitised form to the API. Treat the message as if it could be
 * surfaced to the operator's browser.
 */
class SpeechToTextException extends RuntimeException {}
