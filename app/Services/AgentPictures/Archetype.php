<?php

declare(strict_types=1);

namespace Spora\Services\AgentPictures;

/**
 * Agent profile-picture archetype — the high-level "kind of agent" the
 * operator picks (researcher, writer, coder, …). Each archetype has 3
 * visual variants in the frontend's `Avatar.vue` registry.
 *
 * The 8 chosen archetypes cover the agent classes the platform already
 * ships templates for (assistant, researcher, writer, coder) plus the
 * other common archetypes operators reach for (analyst, explorer,
 * advisor, creative). Extending the registry is a frontend-only change —
 * no schema migration needed to add new entries here, *as long as the
 * new value is added before this file ships to Packagist*. Operators on
 * an older release would see the new archetype as "unknown" and the
 * frontend would fall back to the default; the server still validates
 * the enum.
 *
 * Values are wired into {@see Palette} for the colored-tile rendering
 * and into the AgentTemplateExporter output (see
 * `metadata.archetype`).
 */
enum Archetype: string
{
    case Assistant = 'assistant';
    case Researcher = 'researcher';
    case Analyst = 'analyst';
    case Writer = 'writer';
    case Coder = 'coder';
    case Explorer = 'explorer';
    case Advisor = 'advisor';
    case Creative = 'creative';
}
