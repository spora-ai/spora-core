<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Psr\Log\NullLogger;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolsRecommendsSkillsValidator;
use Spora\Skills\SkillScanner;
use Spora\Tools\AgentTool;
use Spora\Tools\AskUserQuestionTool;
use Spora\Tools\CalculatorTool;
use Spora\Tools\MediaTool;
use Spora\Tools\ReadUrlTool;
use Spora\Tools\ScheduleTool;
use Spora\Tools\SkillTool;
use Spora\Tools\SubAgentTool;
use Spora\Tools\TimeTool;
use Spora\Tools\TodoTool;
use Spora\Tools\UserInfoTool;

/**
 * Build-time gate for the strict-mode contract.
 *
 * Every concrete tool class shipped by spora-core declares its
 * `#[Tool(recommendsSkills: ...)]` slugs against the framework's bundled
 * skill roots; if a core tool references a slug that is not on disk,
 * this test fails CI on PR — same intent as
 * `SkillScannerTest::scan() finds the bundled time-arithmetic skill via
 * the framework path`, but as a per-PR regression.
 *
 * Plugin authors must mirror this in their own test suites over their
 * own scanner roots — see the strict-mode docs.
 */
test('all spora-core tools declare recommendsSkills slugs that exist on disk', function (): void {
    $toolClasses = [
        TimeTool::class,
        CalculatorTool::class,
        ReadUrlTool::class,
        UserInfoTool::class,
        SubAgentTool::class,
        AgentTool::class,
        SkillTool::class,
        MediaTool::class,
        TodoTool::class,
        AskUserQuestionTool::class,
        ScheduleTool::class,
    ];

    $frameworkSkills = BASE_PATH . '/skills';
    if (!is_dir($frameworkSkills)) {
        $this->markTestSkipped("Framework skills directory not present at {$frameworkSkills}.");
    }

    $scanner = new SkillScanner([
        ['path' => $frameworkSkills, 'source' => 'core'],
    ]);
    $resolver = new ToolConfigNameResolver(new NullLogger(), $toolClasses);
    $validator = new ToolsRecommendsSkillsValidator($resolver, $scanner);

    expect($validator->validate())->toBe([]);
});
