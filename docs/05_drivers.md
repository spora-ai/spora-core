# LLM Drivers

Spora's LLM layer is built around `LLMDriverConfigInterface`. Drivers are resolved per-request by `DriverFactory` based on the agent's `llm_driver_config_id` FK into `LLMDriverConfiguration`.

---

## `LLMDriverConfigInterface`

**File:** `app/Drivers/LLMDriverConfigInterface.php`

Every driver must implement:
- `getName(): string` — snake_case identifier, e.g. `openai_compatible`
- `getDisplayName(): string` — human-readable, e.g. `OpenAI Compatible`
- `getSettingsSchema(): array` — `#[ToolSetting]` reflection attributes for the driver's required fields
- `getDefaultTools(): array` — default tool list for this driver

---

## `LLMDriverConfiguration` (Model)

**File:** `app/Models/LLMDriverConfiguration.php`  
**Table:** `llm_driver_configurations`

| Column | Type | Description |
|---|---|---|
| `id` | bigInt | Primary key |
| `user_id` | bigInt FK | Owner (multi-tenant isolation) |
| `name` | varchar(100) | User's friendly name: "Production GPT-4" |
| `driver_class` | varchar(200) | FQCN implementing `LLMDriverConfigInterface` |
| `settings` | text (encrypted) | Driver-specific JSON (api_key, base_url, model, etc.) |
| `is_default` | boolean | Default for this user if no agent-specific config set |
| `created_at` / `updated_at` | timestamp | |

Settings are encrypted at rest using `SecurityManager`. The UI always receives masked values (`***`) for password fields.

---

## `OpenAICompatibleDriver`

**File:** `app/Drivers/OpenAICompatibleDriver.php`  
**Driver name:** `openai_compatible`

- Calls `POST {base_url}/chat/completions` using the standard OpenAI chat completions format.
- `base_url` defaults to `https://api.openai.com/v1`; can be overridden for Ollama, Groq, LM Studio, Azure, etc.
- Reads settings from `LLMDriverConfiguration.settings` (encrypted blob decrypted via `LLMConfigService`).
- Parses `finish_reason: tool_calls` for tool dispatch; `finish_reason: stop` for text responses.

Settings schema (`#[ToolSetting]` attributes): `api_key` (password), `base_url` (text), `model` (text), `temperature` (text), `max_tokens` (text).

---

## `AnthropicCompatibleDriver`

**File:** `app/Drivers/AnthropicCompatibleDriver.php`  
**Driver name:** `anthropic_compatible`

- Calls `POST {base_url}` using Anthropic's `messages` API. Default `base_url` is `https://api.anthropic.com/v1/messages`.
- Uses Anthropic's request format: `system` prompt is a top-level field; `tools` array uses the Anthropic schema (not OpenAI schema).
- Parses `stop_reason: tool_use` to extract `tool_use` content blocks; `stop_reason: end_turn` to extract `text` blocks.

Settings schema: `api_key` (password), `base_url` (text), `model` (text), `thinking_budget` (text).

---

## `DriverFactory`

**File:** `app/Drivers/DriverFactory.php`

- Resolves `agent.llm_driver_config_id` to a `LLMDriverConfiguration` model.
- Instantiates the configured `driver_class`.
- Merges plugin-registered drivers, allowing plugins to contribute new providers at boot.
- Falls back to the user's default config (`is_default = true`) if agent has no `llm_driver_config_id`.

---

## Dependencies

- `symfony/http-client ^7.0` — HTTP transport for all driver requests.

---

## Tests

- `tests/Unit/LLMDriverConfigInterfaceTest.php` — Interface contract for built-in drivers
- `tests/Unit/LLMConfigServiceTest.php` — Encryption, getDrivers, getEffectiveSettings
- `tests/Unit/OpenAICompatibleDriverTest.php`
- `tests/Unit/AnthropicCompatibleDriverTest.php`

Both driver test suites use mocked HTTP responses and cover: tool call parsing, text response parsing, error handling, and rate-limit exception propagation.
