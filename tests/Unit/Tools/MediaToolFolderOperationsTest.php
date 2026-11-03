<?php

declare(strict_types=1);

use Spora\Core\Kernel;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\MediaTool;

/**
 * The folder operations live on the media tool.
 *
 * They were a second tool (`media_folders`) until `create_media` grew a
 * `folder_id`: the assistant had to know two tools — and two parameter
 * vocabularies — to file one file. The split also drew a line that was
 * already crossed, so the line was where the incoherence was.
 */
it('declares the folder operations on the media tool', function (): void {
    $kernel = new Kernel();
    $container = $kernel->getContainer();
    $tool = $container->get(MediaTool::class);

    $operations = array_map(
        static fn($operation): string => $operation->name,
        $tool->getOperations(),
    );

    expect($operations)->toContain(
        'list_folders',
        'create_folder',
        'rename_folder',
        'delete_folder',
        'file_asset',
        'search_assets',
    );

    unset($kernel);
    gc_collect_cycles();
});

it('no longer ships a second tool for the folders', function (): void {
    $kernel = new Kernel();
    $container = $kernel->getContainer();

    $names = array_map(
        static function (string $class): string {
            $attributes = (new ReflectionClass($class))->getAttributes(Tool::class);

            return $attributes === [] ? '' : $attributes[0]->newInstance()->name;
        },
        $container->get('tool_classes'),
    );

    expect($names)->not->toContain('media_folders');

    unset($kernel);
    gc_collect_cycles();
});

it('creates a folder through the tool', function (): void {
    // The dispatch the assistant calls: one tool, one parameter
    // vocabulary.
    $kernel = new Kernel();
    $container = $kernel->getContainer();
    $tool = $container->get(MediaTool::class);
    $principalId = createUserPrincipalPublic(720);

    $result = $tool->execute(
        ['action' => 'create_folder', 'name' => 'Corporate'],
        1,
        null,
        new PrincipalContext($principalId, 'user', 1, 1),
    );

    expect($result->success)->toBeTrue();
    expect($result->data['folder']['path'])->toBe('/Corporate');
    expect((new MediaFolderService())->find($principalId, $result->data['folder']['id']))->not->toBeNull();

    unset($kernel);
    gc_collect_cycles();
});

it('files an asset through the tool', function (): void {
    $kernel = new Kernel();
    $container = $kernel->getContainer();
    $tool = $container->get(MediaTool::class);
    $principalId = createUserPrincipalPublic(721);
    $folder = (new MediaFolderService())->create($principalId, 'Corporate');

    $result = $tool->execute(
        ['action' => 'search_assets', 'folder_id' => (string) $folder->id],
        1,
        null,
        new PrincipalContext($principalId, 'user', 1, 1),
    );

    expect($result->success)->toBeTrue();
    expect($result->data['total'])->toBe(0);

    unset($kernel);
    gc_collect_cycles();
});
