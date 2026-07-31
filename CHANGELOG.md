# Changelog

All notable changes to `spora-ai/spora-core` will be documented in this file.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.13.0] — 2026-07-31

Minor release. Headline features:

- **feat(agents): operator-chosen profile picture** (#174) — agents can now
  carry an archetype avatar or an operator-uploaded image. Powers the
  profile-picture picker in `spora-frontend` v0.9.0 (#84).
- **feat(agent-template): opt-in export of agent tool settings** (#177) —
  agents can be saved as templates with non-secret configuration included.
  Powers the template settings export UI in `spora-frontend` v0.9.0 (#86).
- **fix(release): drop `version` field from composer.json** (#176) —
  `composer` reads the installed version from the git tag, so the field
  was duplicative and drifted out of sync on every release.

Downstream requirement: `spora-frontend` v0.9.0 expects `spora-core` v0.13.0
APIs (#174, #177). The next `spora-ai/spora` skeleton deps bump should
pick up both.

> **Note (correction to the v0.13.0 tag annotation):** the `git tag -m`
> message for `v0.13.0` mistakenly claimed that `spora-plugin-minimax` was
> bumped from v0.8.0 to v0.8.1 as part of this release. That is wrong —
> the plugin's latest tag is still `v0.8.0`, and there is no v0.8.1.
> Nothing in the Spora plugin set was version-bumped alongside spora-core
> v0.13.0. The tag annotation is not deletable on Packagist (re-pushing
> the same tag name is rejected), so this changelog entry is the
> authoritative release note going forward.

## [0.12.1] — 2026-07-30

Patch release — fix agent-template `required_plugins` contract to accept
`vendor/name` (e.g. `spora-ai/spora-plugin-minimax`) end-to-end:

- `PluginLoader::getSlugForPackageName()` resolves `vendor/name` to its slug
- `AgentTemplateImporter` + `Validator` + `agent-template.schema.json`
  accept `vendor/name` only
- `skills/agent-creation/{SKILL.md, example.json}` + tests updated
- Export → re-import round trip now succeeds with zero `PLUGIN_MISSING`

[Unreleased]: https://github.com/spora-ai/spora-core/compare/v0.13.0...main
[0.13.0]:    https://github.com/spora-ai/spora-core/compare/v0.12.1...v0.13.0
[0.12.1]:    https://github.com/spora-ai/spora-core/compare/v0.12.0...v0.12.1
