<?php

declare(strict_types=1);

namespace Spora\Extensions;

/**
 * Marker interface for the project-level App extension.
 *
 * Lives at `<BASE_PATH>/app/App.php` (configurable via SPORA_APP_DIR),
 * discovered by {@see AppLoader} via reflection — no `plugin.json` manifest
 * is needed because the App is project-local and one per installation.
 *
 * The hook surface is identical to {@see \Spora\Plugins\PluginInterface}.
 * To promote an App to a distributable plugin: rename the file to `Plugin.php`,
 * rename the class to `Plugin`, add `plugin.json`, ship as a Composer package.
 * No internal refactor required.
 */
interface AppInterface extends SporaExtensionInterface {}
