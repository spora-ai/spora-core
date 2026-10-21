<?php

declare(strict_types=1);

use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;
use Spora\Tools\AskUserQuestionTool;
use Spora\Tools\TimeTool;

/**
 * The shipped catalogues, end to end.
 *
 * The other i18n tests use fixture catalogues, so they prove the
 * mechanism. This one uses core's own `locales/de.json` and the real
 * tools, so it proves the wiring: a tool's approval prompt comes out of
 * `describeAction()` in English, and the operator reads it in their own
 * language with the argument still in it.
 */
test('an approval prompt reaches the operator in the operator\'s language', function (): void {
    $translator = new Translator(new CatalogLoader());
    $translator->useLocale('de');

    $english = (new AskUserQuestionTool())->describeAction(['questions' => [['question' => 'Which one?']]]);
    $german = $translator->trans($english);

    expect($english)->toBe('Ask the operator 1 question')
        ->and($german)->not->toBe($english)
        ->and($german)->toContain('1');
});

test('a prompt with an argument keeps the argument in the translation', function (): void {
    $translator = new Translator(new CatalogLoader());
    $translator->useLocale('de');

    $english = (new TimeTool())->describeAction(['action' => 'format', 'epoch' => 1753464720]);
    $german = $translator->trans($english);

    expect($english)->toBe('Format epoch 1753464720 as datetime')
        ->and($german)->not->toBe($english)
        ->and($german)->toContain('1753464720');
});
