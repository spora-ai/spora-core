# Speech-to-text provider configuration

Operator guide for configuring the Spora speech-to-text (STT) surface.
This document covers the *what* and the *why*; the API contract lives
in [`docs/04_api.md`](04_api.md#speech-provider-configuration) and the
underlying provider implementations in
[`OpenAiCompatibleTranscriber`](../app/Speech/OpenAiCompatibleTranscriber.php)
plus any plugin-contributed
[`SpeechToTextProviderInterface`](../app/Speech/SpeechToTextProviderInterface.php)
classes (today: [`spora-plugin-muse`](../spora-plugin-muse) and
[`spora-plugin-minimax`](../spora-plugin-minimax) for STT).

## Overview

Two surfaces configure the recording button inside Spora's composers:

1. **Admin → Speech Providers** (`/admin/settings/speech-providers`) — global defaults shared by every authenticated user. Admin-only writes; the form opens here when the recording button is missing because the operator has never set up a provider.
2. **User Settings → Speech** (`/settings/speech`) — per-user overrides. Useful for users whose workflow needs a different vendor (e.g. a personal Groq key for fast drafts) without changing what the rest of the team uses.

Both surfaces write to a single storage layer: the `speech_provider_configurations` table. The wire shape and validation rules are the same across the two surfaces; the cascade (per-user → per-group → global default → schema defaults) is handled by
[`SpeechToTextRegistry`](../app/Speech/SpeechToTextRegistry.php) when
*picking* the provider class, and by `SpeechToTextRegistry`'s
`bindSettings()` call when *pushing* the resolved settings into the
provider's `transcribe()` call.

## Storage model

The v1-era `tool_configurations` / `tool_user_settings` tables no
longer drive STT settings. The single source of truth is
`speech_provider_configurations`:

| Column | Purpose |
|---|---|
| `id` | Surrogate PK. |
| `principal_id` | `null` for global rows; otherwise a user-principal or group-principal id from `principals`. |
| `provider_class` | FQCN of a registered [`SpeechToTextProviderInterface`](../app/Speech/SpeechToTextProviderInterface.php) implementation. |
| `display_name` | Operator-facing label surfaced in the recording-button tooltip and the Capability endpoint. |
| `settings` | Encrypted JSON; password fields per-row encrypted via `SecurityManager`. |
| `is_global` / `is_default` | Tier-4 marker (`is_global=true AND is_default=true` is the operator-set fallback). |

The two-tier XOR invariant (`principal_id IS NULL XOR is_global`) is
enforced at the model layer
([`SpeechProviderConfiguration::validateGlobalXor()`](../app/Models/SpeechProviderConfiguration.php))
because MySQL 5.7 and some SQLite engine versions silently ignore
`CHECK` clauses.

The `principal_preferences.preferred_speech_config_id` FK is what
backs the per-user "use this provider" toggle; same FK shape as
`agents.speech_driver_config_id` for per-agent pinning.

## How to configure Mistral Voxtral (via OpenAI-Compatible)

Mistral's Voxtral Mini uses the same multipart wire shape as OpenAI
Whisper (`POST {base_url}/audio/transcriptions` with `file`, `model`,
optional `language`, Bearer auth, JSON response with a `text` field).
Spora handles it through `OpenAiCompatibleTranscriber` — no separate
plugin needed.

1. Sign in as an admin and navigate to **Admin → Speech Providers** (`/admin/settings/speech-providers`).
2. Click **Create**.
3. Choose the **OpenAI Compatible** card. The form renders six fields:

   | Field | Value |
   |---|---|
   | Display name | `Mistral Voxtral (prod)` — pick a name that distinguishes this config from others if you run more than one |
   | API key | your Mistral API key from [console.mistral.ai](https://console.mistral.ai/) |
   | Base URL | `https://api.mistral.ai/v1` |
   | Model | `voxtral-mini-latest` |
   | Language hint (BCP-47) | leave blank for auto-detect; set `en-US` if you always record in English |
   | HTTP timeout (seconds) | `60` |

4. Click **Save**. The list view shows your new config with its
   `Global` badge. Navigate to a chat composer and click the recording
   button — it should now be enabled.

If the button stays disabled, check **Settings → Tools → OpenAI
Compatible (Mistral)** (or your `display_name`) — the form surfaces
whether the `api_key` was accepted and whether the operator's
network can reach `api.mistral.ai`.

## How to configure OpenAI Whisper

OpenAI Whisper goes through the same `OpenAiCompatibleTranscriber`
provider — change two fields:

| Field | Value |
|---|---|
| Display name | `OpenAI Whisper` |
| Base URL | `https://api.openai.com/v1` |
| Model | `whisper-1` |

Everything else is identical to the Mistral setup.

## How to configure Meta Muse (via plugin)

The Meta Muse plugin (`spora-plugin-muse`) ships a bespoke STT
provider that handles Meta's multipart wire shape and ffmpeg-based
preprocessing. Once installed, Muse appears as a second card on the
provider picker — no migration is needed.

The Muse plugin's own `[ToolSetting]` attributes are picked up by the
schema endpoint live, so any new fields the plugin declares
automatically appear in the form. You don't need to touch Spora core.

If you don't see the Muse card, the plugin isn't installed or its
`plugin.json` declares a different `type` — check
`/admin/plugins` for the install status.

## Per-user overrides

Every user can set their own per-principal override by setting
`principal_preferences.preferred_speech_config_id` to point at a
`speech_provider_configurations` row under their user-principal.

To create a personal override:

1. As a non-admin user, navigate to **User Settings → Speech** (`/settings/speech?create=1`).
2. Pick the provider class you want to override.
3. Fill in only the fields you want to differ from the global
   default. Empty fields inherit the global value; the form sends
   the raw settings to `POST /api/v1/speech/provider-configs` with
   `scope: "user"`.

The Capability endpoint surfaces the resolved effective label, so the
recording button in the composer shows the user's override name when
it's set.

## Per-agent STT override (Agent Settings → Speech)

If an operator wants agent X to use Voxtral for production chats and
agent Y to use Whisper for the support inbox, set
`agents.speech_driver_config_id` to point at the chosen
`speech_provider_configurations.id`. The transcribe controller threads
the agent id through to the registry so the per-agent override wins
on tier 1 of the cascade.

Enable the override:

1. Open the agent's settings page.
2. Add a **Speech** section if not already present.
3. Pick the provider config and save. The FK is updated and the
   cascade picks it on the next transcribe request.

When no override is set, the cascade falls through to user → group →
global as before.

## Per-group STT configuration (Group Settings → Speech)

A team of users that wants to share a Mistral key (and split the bill)
can attach a group-scoped STT config. Every member of the group gets
the same effective settings; a user with a personal override still
wins on conflict.

Auth:

- **Group admin / group owner** can write the group's config.
- **Global admin** can write any group's config.

Other members of the group can read the group config (e.g. the
Capability endpoint's "configured: true" still shows because the
group cascade has at least one valid key), but can't edit it. Users
who aren't members of the group don't see the config — list access
is membership-gated for the existence-hide invariant (a non-member
sees an empty list so they can't tell whether the group has an STT
config).

Enable the group config:

1. As a group admin, open the group's settings page.
2. Open the **Speech** section.
3. Pick the provider class and fill in the settings.
4. Save. The cascade picks it on the next transcribe request for any
   group member.

Storage is the same `speech_provider_configurations` table; the
row's `principal_id` is the group's group-principal id (rather than
the caller's user-principal id).

## The cascade: agent → user → group → global → first-registered

The class pick walks a five-tier cascade in
[`SpeechToTextRegistry`](../app/Speech/SpeechToTextRegistry.php) +
[`SpeechToTextCascadeResolver`](../app/Speech/SpeechToTextCascadeResolver.php):

```
agent.speech_driver_config_id
  → principal_preferences.preferred_speech_config_id (user-principal)
  → principal_preferences.preferred_speech_config_id (each group-principal, joined_at ASC)
  → speech_provider_configurations WHERE is_global = true AND is_default = true
  → first registered SpeechToTextProviderInterface class (fallback)
```

Read aloud:

1. **Agent override** — `agents.speech_driver_config_id` points at a
   `speech_provider_configurations.id`. If the resolved class is
   registered, that row wins.
2. **User preference** — `principal_preferences.preferred_speech_config_id`
   for the caller's user-principal. Same resolution as tier 1.
3. **Group preference** — for every group the caller belongs to
   (ordered by `group_memberships.joined_at ASC`), check that group's
   preference; first match wins.
4. **Global default** — `speech_provider_configurations WHERE is_global = true AND is_default = true`. First match wins, ordered `updated_at DESC, id DESC`.
5. **First-registered-wins fallback** — the first provider in
   constructor order, returned with no FK backing. Pre-existing
   behaviour preserved so an operator with no preferences / agent
   override / global default still gets the same fallback they did
   before this PR.

Once the class is picked, the same row's `settings` column is
decoded via
[`SpeechProviderConfigPersistence::decodeSettings()`](../app/Services/SpeechProviderConfigPersistence.php)
and pushed into the provider via
[`bindSettings()`](../app/Speech/SpeechToTextProviderInterface.php#bindsettingsarray-settings-void).
The provider's `transcribe()` consults `$boundSettings` first,
falling back to `ToolConfigService::getEffectiveSettings()` only when
no bound settings are present (legacy v1 operators whose only config
is in `tool_user_settings` / `tool_configurations`).

The capability endpoint reflects tier 1-4's class via `effective_class`
+ `effective_source` + `effective_config_id` on every provider row. Each
row also carries `class` (the row's **own** provider FQCN — distinct
from `effective_class`, which is the cascade-resolved class repeated
across rows) and `preferred_audio_mimes: list<string>` — the recorder
uses these to pick a `MediaRecorder`-supported container the active
provider actually accepts. Sources:

  - The provider class's `#[AcceptedAudioMime]` attribute declaration,
    when present.
  - The common-superset default
    (`['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm', 'audio/wav']`)
    when the provider doesn't declare any. Plugin authors are
    encouraged to declare their own preference order — e.g. MiniMax
    prefers OGG over Opus (Chrome 105+ + Firefox natively supported)
    ahead of MP4 (Safari) ahead of legacy WebM (avoided because the
    Matroska container is the only format MiniMax rejects).

## Display names and collision behaviour

Two configs (one global, one user-scope) can both be named `Mistral
Voxtral`. The Capability endpoint picks one — the user-scope wins, the
global becomes invisible. Use a distinguishing suffix like `(prod)` /
`(staging)` when configuring both global and personal overrides. (The
uniqueness enforcement is a follow-up plan tracked in the backlog.)

## `api_key` round-trip

`settings.api_key` is masked as `"***"` on every read through this
endpoint — the operator never sees the stored key. When you submit a
form with `api_key: "***"`, Spora keeps the existing value unchanged
(matches the convention `ToolController` uses for tool API keys).
Submit a new key to rotate.

## Reference

- `config_id` on the Capability endpoint row is populated by
  `ToolConfigService::globalConfigId($provider::class)` — a thin
  lookup against `tool_configurations.id` for `OpenAiCompatibleTranscriber`
  rows. Class-level providers (Muse) keep `config_id: null` since they
  have no row in `tool_configurations`. The SPA uses it to deep-link
  the Capability row into the config edit form.
- `display_name` on each row in `provider-configs` is the operator-visible
  label; `provider_display_name` is the class-level fallback
  (`"OpenAI Compatible"` / `"Meta Muse Voice Transcribe"`). The SPA
  binds `display_name` to the form title and the recording button's
  tooltip.