<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

/**
 * App whose class declaration is invalid for AppLoader (not implementing
 * SporaExtensionInterface). Used to assert the validation error path.
 */
final class InvalidApp {}
