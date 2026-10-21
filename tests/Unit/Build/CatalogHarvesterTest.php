<?php

declare(strict_types=1);

use Spora\Build\CatalogHarvester;
use Spora\Build\I18nCheckCommand;

/**
 * The harvest is what makes the never-translated list checkable, so these
 * assert both directions: a string that is in scope is harvested, and a
 * string that is not is not.
 */
test('a Tool display name, its category and a ToolSetting description are harvested', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    expect($harvested)->toHaveKey('Calculator')
        ->and($harvested)->toHaveKey('productivity')
        ->and($harvested)->toHaveKey('Seconds to wait for a model response before giving up.');
});

test('every harvested message carries a file:line locator', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    $locators = array_merge(...array_values($harvested));

    expect($locators)->not->toBeEmpty()
        ->and($locators)->each->toContain('/');
});

test('a ToolSetting label and its option labels are never harvested', function (): void {
    $harvested = array_keys((new CatalogHarvester(dirname(__DIR__, 3)))->harvest());

    // "API Key" is the label on six attributes across the tree and enters
    // the model's prompt through `expose_to_llm`; the catalogue key is the
    // English text, so a translated label would hit every setting reusing
    // the word. MediaTool's `scope` options are the same rule — most are
    // language endonyms that must stay verbatim.
    expect($harvested)->not->toContain('API Key')
        ->and($harvested)->not->toContain('Base URL')
        ->and($harvested)->not->toContain('Temperature')
        ->and($harvested)->not->toContain('Only media created by this agent')
        ->and($harvested)->not->toContain('Library scope');
});

test('a declared setting label, description and option label are harvested', function (): void {
    $harvested = array_keys((new CatalogHarvester(dirname(__DIR__, 3)))->harvest());

    expect($harvested)->toContain('Default language')
        ->and($harvested)->toContain('Warning')
        ->and($harvested)->toContain('SMTP — deliver through a mail server');
});

test('the language picker options stay out of the catalogue', function (): void {
    $harvested = array_keys((new CatalogHarvester(dirname(__DIR__, 3)))->harvest());

    // A user finds their own language by reading it in that language, so
    // `Deutsch` must read `Deutsch` in the German catalogue — which the
    // identical-value rule would otherwise reject as untranslated.
    expect($harvested)->not->toContain('English')
        ->and($harvested)->not->toContain('Deutsch');
});

test('an error message reaches the catalogue with the line it is written on', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    expect($harvested)->toHaveKey('Request body must be valid JSON.')
        ->and(implode(' ', $harvested['Request body must be valid JSON.']))
            ->toContain('app/Http/AgentTemplateController.php:105');
});

test('a helper with its own argument order still yields the message, not the code', function (): void {
    $harvested = array_keys((new CatalogHarvester(dirname(__DIR__, 3)))->harvest());

    // MediaResolveController declares `error(int $status, string $code,
    // string $message)`; the second argument there is a wire code, not
    // copy.
    expect($harvested)->toContain('You must be logged in to resolve media.')
        ->and($harvested)->not->toContain('UNAUTHORIZED');
});

test('the identity map maps every harvested message to itself', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    foreach (CatalogHarvester::identityMap($harvested) as $key => $value) {
        expect($value)->toBe($key);
    }
});

test('a plugin manifest description is not harvested into core\'s catalogue', function (): void {
    // The plugin ships the description in its own catalogue and the host
    // resolves it through the scope chain. Harvesting it here would make
    // core's catalogue depend on which plugins happen to be installed in
    // the working copy.
    $base = sys_get_temp_dir() . '/spora-harvest-' . bin2hex(random_bytes(6));
    mkdir($base . '/plugins/demo', 0o777, true);
    file_put_contents($base . '/plugins/demo/plugin.json', (string) json_encode([
        'slug'        => 'demo',
        'description' => 'A description that belongs to the plugin.',
    ]));

    $harvested = (new CatalogHarvester($base))->harvest();

    expect($harvested)->not->toHaveKey('A description that belongs to the plugin.');

    unlink($base . '/plugins/demo/plugin.json');
    rmdir($base . '/plugins/demo');
    rmdir($base . '/plugins');
    rmdir($base);
});

test('the committed en.json matches the source', function (): void {
    $base = dirname(__DIR__, 3);
    $harvested = array_keys((new CatalogHarvester($base))->harvest());
    sort($harvested);

    $committed = array_keys(json_decode((string) file_get_contents($base . '/locales/en.json'), true));
    sort($committed);

    // This is `i18n:check` rule 1, asserted where the failure points at
    // the message rather than at a console transcript.
    expect($harvested)->toEqual($committed);
});

test('a message thrown as an exception argument is harvested', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    // A service throws with the copy; a controller puts `$e->getMessage()`
    // into the error envelope, and the router translates what lands there.
    // The literal sits in `app/Services`, which the envelope harvest never
    // reads, so only the thrown-message harvest can reach it.
    expect($harvested)->toHaveKey('Hex payload has odd length.')
        ->and(implode(' ', $harvested['Hex payload has odd length.']))
            ->toContain('app/Services/MediaArchive/MediaIngestDecoder.php:76');
});

test('a sprintf in an envelope position is harvested by its format string', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    // The format string *is* the message: the placeholders stay in the
    // translation, and the runtime fills them in.
    expect($harvested)->toHaveKey('MIME type "%s" is not in the upload allowlist.')
        ->and(implode(' ', $harvested['MIME type "%s" is not in the upload allowlist.']))
            ->toContain('app/Http/MediaUploadController.php:118');
});

test('an approval prompt is harvested as the format string it was built with', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    // The approval prompt is what the operator reads before they approve a
    // tool call, so it has to be in the catalogue. `sprintf` is the shape
    // the harvest reads: the format string *is* the message, the
    // placeholder stays in it, and the runtime fills the slot with the
    // argument the operator sees.
    expect($harvested)->toHaveKey('List scheduled runs for %s.')
        ->and($harvested)->toHaveKey('Media get_media(%s)')
        ->and($harvested)->toHaveKey('Ask the operator %d question');
});

test('every prompt of a describeAction is harvested, not only the first ones', function (): void {
    $harvested = (new CatalogHarvester(dirname(__DIR__, 3)))->harvest();

    // A double-quoted interpolation ends the method body early for the
    // harvest: the closing brace of `{$var}` is a character token, so the
    // brace count that ends the body ends it there. Everything behind
    // that point was invisible — including the prompts that were already
    // `sprintf`.
    expect($harvested)->toHaveKey('Trigger scheduled run now (%s, %s).')
        ->and($harvested)->toHaveKey('Delete scheduled run (%s, %s, destructive).');
});

test('a thrown message built by concatenation is not harvested', function (): void {
    $harvested = array_keys((new CatalogHarvester(dirname(__DIR__, 3)))->harvest());

    // `'a' . $b` has no whole message to key on — only the part that is
    // really there would be, and translating that would ship a fragment.
    expect($harvested)->not->toContain(
        'No secret key configured. Set SPORA_SECRET_KEY (base64 32 bytes) or SPORA_KEY_PATH, ',
    );
});

test('the messages of a tool and of a CLI command are never harvested', function (): void {
    $harvested = array_keys((new CatalogHarvester(dirname(__DIR__, 3)))->harvest());

    // A tool's failure text becomes `ToolResult` content and is model
    // input; CLI output is permanently English. Both are messages nobody
    // outside the agent or the terminal ever reads.
    expect($harvested)->not->toContain('list_schedules: agent not found.')
        ->and($harvested)->not->toContain('No Spora plugins installed.');
});

test('vue-i18n compilation syntax in a message is reported as an error', function (string $message): void {
    expect(I18nCheckCommand::unsafeMessage($message))->toBeTrue();
})->with([
    'a bare brace'  => 'Use { and } for a placeholder.',
    'a pipe'        => 'one | two',
    'an empty slot' => 'The value {} is missing.',
]);

test('a named placeholder and an escaped literal brace are not reported', function (string $message): void {
    expect(I18nCheckCommand::unsafeMessage($message))->toBeFalse();
})->with([
    'a parameter slot'   => 'The provider POSTs to {base_url}/audio/transcriptions.',
    'an escaped brace'   => "Write {'{'} and {'}'} to show a brace, and {'|'} for a pipe.",
    'ordinary copy'      => 'Save changes to continue.',
]);
