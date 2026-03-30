<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Applied at class level on InputToolInterface implementors.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class InputTool {}
