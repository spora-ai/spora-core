<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Exceptions;

use RuntimeException;

/**
 * Thrown when {@see \Spora\Services\MediaArchive\MediaDerivativeService::produceDerivative()}
 * walks the registered producers and none of them advertises support for
 * the requested source/format pair.
 *
 * The HTTP controller maps this to a 409 Conflict (no producer available);
 * the LLM tool maps it to a `ToolResult::fail()` so the assistant sees a
 * human-readable explanation and can retry with a different `format`.
 *
 * Not `final` so plugin producers can extend it for richer cases (e.g.
 * "unsupported MIME" vs "unsupported format") without forking the base
 * service signature.
 */
class NoDerivativeProducerException extends RuntimeException {}
