<?php

declare(strict_types=1);

/*
 * Test helper for legacy user-shim methods that moved from
 * {@see \Spora\Services\ToolConfigService} to
 * {@see \Spora\Services\ToolConfigUserAdapter} during the S1448 split.
 */

use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigUserAdapter;

if (! function_exists('makeUserAdapter')) {
    function makeUserAdapter(Spora\Services\ToolConfigServiceInterface $service): ToolConfigUserAdapter
    {
        return new ToolConfigUserAdapter(
            $service,
            new PrincipalService(new PrincipalResolver()),
        );
    }
}
