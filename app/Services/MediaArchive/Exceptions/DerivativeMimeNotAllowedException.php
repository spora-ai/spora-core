<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Exceptions;

use RuntimeException;

/**
 * Thrown when a producer emits a script-capable MIME type that the
 * operator has not permitted.
 *
 * The upload gate already refuses these formats; this is the derivative
 * path's equivalent. It exists because the gate was enforced in one place
 * only, and a rule enforced at a single choke point cannot cover a path
 * that does not pass through it — the Typst plugin's SVG producer wrote
 * `image/svg+xml` rows the upload gate would have refused.
 *
 * The HTTP controller maps this to a 422; the LLM tool maps it to a
 * `ToolResult::fail()` so the assistant sees a human-readable explanation.
 */
class DerivativeMimeNotAllowedException extends RuntimeException {}
