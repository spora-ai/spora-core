<?php

declare(strict_types=1);

use Spora\I18n\CatalogLoader;
use Spora\I18n\LocaleResolver;
use Spora\I18n\Translator;
use Spora\Models\ToolCall;
use Spora\Services\ToolCallSerializer;
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
    $catalogs = new CatalogLoader();
    $translator = new Translator($catalogs);

    // The operator's own locale, resolved the way the kernel resolves it:
    // the stored locale, then the instance default, then English.
    $translator->useLocale((new LocaleResolver($catalogs))->resolve('de'));

    $english = (new AskUserQuestionTool())->describeAction(['questions' => [['question' => 'Which one?']]]);

    // The payload the operator's UI reads: the description is stored in
    // English and translated at serialization time, so the operator reads the
    // language they are working in.
    $toolCall = new ToolCall();
    $toolCall->setRawAttributes([
        'id'                    => 42,
        'provider_call_id'      => 'pc_abc',
        'tool_name'             => 'ask_user_question',
        'tool_class'            => AskUserQuestionTool::class,
        'tool_type'             => 'output',
        'status'                => 'PENDING_APPROVAL',
        'operation'             => 'run',
        'operation_description' => $english,
        'human_description'     => $english,
        'proposed_arguments'    => json_encode(['questions' => [['question' => 'Which one?']]]),
        'approved_arguments'    => null,
        'result_content'        => null,
        'result_data'           => null,
        'executed_at'           => null,
    ]);

    $payload = (new ToolCallSerializer([], null, $translator))->toArray($toolCall);

    expect($english)->toBe('Ask the operator 1 question')
        ->and($payload['human_description'])->toBe('Den Bediener 1 Frage stellen');
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
