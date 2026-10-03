<?php

declare(strict_types=1);

use Spora\Skills\SkillScanner;

/**
 * Every skill this framework ships must be clean.
 *
 * `SkillScanner` folds validation errors into the same list as warnings, so an
 * invalid bundled skill is still served — it just carries a finding, and the
 * admin UI badges it. That is a poor way to find out that a shipped file is
 * malformed, which is what happened: `agent-creation` carried an unquoted
 * `allowedByDefault: false`, YAML read it as a bool, and
 * `SkillValidator::METADATA_VALUE_INVALID` flagged the skill on every load.
 *
 * Nothing else asserted this. The other bundled-skill test checks that
 * `time-arithmetic` is found and well-formed, which says nothing about its
 * siblings.
 *
 * The trap worth naming, since it is easy to walk into: `metadata` is a map of
 * string to string and YAML decides types on its own, so `version: 1.0` is a
 * float and `allowedByDefault: false` is a bool — both errors. Quoting is the
 * only way to say "this is the string `1.0`". Asserting on `Skill::metadata()`
 * instead would prove nothing, because it already filters to string keys and
 * values; the validator's finding is the only place the difference shows.
 *
 * The looser alternative — coercing scalars inside `SkillValidator` — would
 * change the contract the custom-skills plugin stores against, and is
 * deliberately not this fix.
 */
test('every bundled skill scans with no validation findings', function (): void {
    $frameworkSkills = BASE_PATH . '/skills';
    if (!is_dir($frameworkSkills)) {
        $this->markTestSkipped("Framework skills directory not present at {$frameworkSkills}.");
    }

    $skills = (new SkillScanner([['path' => $frameworkSkills, 'source' => 'core']]))->scan();
    expect($skills)->not->toBeEmpty();

    $findings = [];
    foreach ($skills as $skill) {
        foreach ($skill->warnings() as $finding) {
            $findings[] = sprintf(
                '%s [%s] %s',
                $skill->name(),
                $finding['code'],
                $finding['message'],
            );
        }
    }

    expect($findings)->toBe([]);
});
