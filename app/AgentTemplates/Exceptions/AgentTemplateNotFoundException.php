<?php

declare(strict_types=1);

namespace Spora\AgentTemplates\Exceptions;

use RuntimeException;

/**
 * Raised when {@see \Spora\AgentTemplates\AgentTemplateImporter::applyTemplate()}
 * is asked for a template id that the scanner can't locate in any of the
 * configured directories. Distinct from generic RuntimeException so the
 * controller can map it to a 404 UNKNOWN_TEMPLATE without catching on type.
 */
final class AgentTemplateNotFoundException extends RuntimeException {}
