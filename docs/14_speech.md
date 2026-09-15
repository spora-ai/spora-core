# Speech-to-text provider configuration

Operator guide for configuring the Spora speech-to-text (STT) surface.
This document covers the *what* and the *why*; the API contract lives
in [`docs/04_api.md`](04_api.md#speech-provider-configuration) and the
underlying provider implementation in
[`OpenAiCompatibleTranscriber`](../app/Speech/OpenAiCompatibleTranscriber.php).

## Overview

Two surfaces configure the recording button inside Spora's composers:

1. **Admin → Speech Providers** (`/admin/settings/speech-providers`) — global defaults shared by every authenticated user. Admin-only writes; the form opens here when the recording button is missing because the operator has never set up a provider.
2. **User Settings → Speech** (`/settings/speech`) — per-user overrides. Useful for users whose workflow needs a different vendor (e.g. a personal Groq key for fast drafts) without changing what the rest of the team uses.

Both surfaces share the same wire shape and validation rules. The cascade (per-user override → global default → schema defaults) is handled inside [`ToolConfigService::getEffectiveSettings()`](../app/Services/ToolConfigService.php) — the UI just writes the right row.

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

Every user can set their own per-principal override. The cascade is:

1. **user-scope setting** (if set) — wins
2. **group-scope setting** (if set, for any group the caller belongs to) — fallback
3. **global setting** (if set) — fallback
4. **schema defaults** — final fallback

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
agent Y to use Whisper for the support inbox, the per-agent STT section
in the agent settings page exposes the override.

Behind the scenes the override lands in `agent_tool_overrides` —
Spora's existing per-agent settings table — keyed on
`(agent_id, tool_class)`. The transcribe controller threads the
agent id through to the registry so the per-agent override is the
last level in the cascade (beats group + user + global).

Enable the override:

1. Sign in and open the agent's settings page.
2. Add a **Speech** section if not already present.
3. Pick the provider class and fill in only the fields that should
   differ from the upstream cascade. The form shows the inherited
   values as form defaults, but any field you set overrides the
   cascade at that key only.

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
4. Save. Members of the group immediately see the recording button
   in composers that consult the group's effective settings.

Storage is the same `tool_user_settings` table; the row's
`principal_id` is the group's group-principal id (rather than the
caller's user-principal id).

## The cascade: global → group → user → agent

Every effective settings read walks the cascade in this order:

```
defaults  →  global  →  group[0..N]  →  user  →  agent_override
```

Read aloud:

1. **Schema defaults** — every `#[ToolSetting]` declaration has a
   `default:` attribute. The cascade fills missing keys with the
   schema default last (so an unset key gets the schema default,
   not nothing).
2. **Global settings** — the operator's
   `tool_configurations` row for the provider class. One row per
   provider class; admin writes.
3. **Group settings (per group the user belongs to)** — every
   `tool_user_settings` row whose `principal_id` points at one of
   the caller's group-principals. Groups are iterated in **principal
   id ascending order** so the iteration is stable across calls;
   the **user-principal wins on conflict** with any group.
4. **User settings** — the `tool_user_settings` row whose
   `principal_id` points at the caller's user-principal. One row
   per user per provider class.
5. **Agent override** — `agent_tool_overrides` keyed on the active
   agent id (per chat / composer). Wins over everything above.

The "last write wins" rule produces a single effective key/value map
that the provider's `transcribe()` call sees. The
`getEffectiveSettingsWithSource()` companion method returns the same
map annotated with which level won per key — the SPA uses that
annotation to render "Inherited from group / Personal override / etc."
badges next to each field.

When the cascade order matters for debugging, the `describe()`
endpoint shows the resolved `display_name` for the OpenAI-compatible
provider — that label is what the recording button's tooltip shows.

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