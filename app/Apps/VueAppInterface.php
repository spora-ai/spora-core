<?php

declare(strict_types=1);

namespace Spora\Apps;

/**
 * Marker extension of {@see AppInterface} for apps that ship a pre-built
 * Vue IIFE bundle alongside their PHP entry point.
 *
 * Plugins whose `AppInterface` does NOT implement {@see VueAppInterface}
 * continue to work — they're treated as "core-owned" apps and routed by
 * the SPA's hard-coded children (`plugins`). Implementing
 * this interface opts the app into the generic `/apps/:appName` loader
 * in `spora-frontend`.
 *
 * The bundle path is relative to `public/plugins/<slug>/`. The default
 * `entry()` returns `main.js`, which matches what the
 * `SporaPluginFrontendInstaller` (in `spora-installer`) drops at runtime.
 */
interface VueAppInterface extends AppInterface
{
    /**
     * Path to the IIFE bundle, relative to `public/plugins/<slug>/`.
     * Must match `build.lib.fileName()` from the plugin's `vite.config.ts`.
     */
    public function entry(): string;
}
