# LLM Drivers

Spora's LLM layer is built around `LLMDriverConfigInterface`. Drivers are resolved per-request by `DriverFactory`, which delegates tier resolution to `LLMConfigService::getEffectiveConfigForAgent()`. Effective config is the `LLMDriverConfiguration` matching the agent's `llm_driver_config_id` FK.

---

## `LLMDriverConfigInterface`

**File:** `app/Drivers/LLMDriverConfigInterface.php`

Every driver must implement (all methods are `static`):
- `getName(): string` — snake_case identifier, e.g. `openai_compatible`
- `getDisplayName(): string` — human-readable, e.g. `OpenAI Compatible`
- `getDefaultTools(): array` — default tool list for this driver

The settings schema is **not** a method on the interface. It is discovered at runtime by `LLMConfigService::buildSchemaFromClass()` via `ReflectionClass::getAttributes(ToolSetting::class)` on the driver class (see `app/Services/LLMConfigService.php:69-88`). Driver classes therefore declare their UI fields with `#[ToolSetting(key: ..., type: ...)]` PHP attributes — no hardcoded field lists.

Driver classes are registered in the PHP-DI container under the `'llm_driver_classes'` array key (see `app/Core/container.php:281-284`). **Note:** the plugin-driven extension of this key is not yet fully wired up — the plugin system's `drivers()` hook is declared on `PluginInterface` and recognised by the boot sequence conceptually, but the explicit `PluginLoader → llm_driver_classes` injection is still a work-in-progress. For now, drivers are typically registered by adding a class to that container key directly (e.g. via a custom `config.php` binding) or by inserting the FQCN into `llm_driver_configurations.driver_class` for an existing config row.

---

## `LLMDriverConfiguration` (Model)

**File:** `app/Models/LLMDriverConfiguration.php`  
**Table:** `llm_driver_configurations`

| Column | Type | Description |
|---|---|---|
| `id` | bigInt | Primary key |
| `user_id` | bigInt FK (nullable) | Owner (multi-tenant isolation). NULL for global configs. |
| `name` | varchar(100) | User's friendly name: "Production GPT-4" |
| `driver_class` | varchar(200) | FQCN implementing `LLMDriverConfigInterface` |
| `settings` | text (JSON) | Driver-specific settings. Password fields are per-field encrypted (see below). |
| `context_window` | unsignedInt (nullable) | Total context window for the model (input + output) |
| `max_tokens_output` | unsignedInt (nullable) | Max output tokens (output buffer) |
| `is_default` | boolean | Global default flag. Default is set only on `is_global=true` rows. |
| `is_global` | boolean | Admin-shared config visible to all users |
| `created_at` / `updated_at` | timestamp | |

Password-typed settings (per the `#[ToolSetting]` schema) are encrypted at rest using `SecurityManager` (`app/Core/SecurityManager.php`) — only `type: 'password'` fields are encrypted; the rest of the settings JSON is stored as plain text. The UI always receives masked values (`***`) for password fields, applied by `LLMConfigService::maskForApi()`.

---

## `OpenAICompatibleDriver`

**File:** `app/Drivers/OpenAICompatibleDriver.php`  
**Driver name:** `openai_compatible`

- Calls `POST {base_url}/chat/completions` using the standard OpenAI chat completions format.
- `base_url` defaults to `https://api.openai.com/v1`; can be overridden for Ollama, Groq, LM Studio, Azure, etc.
- Reads settings from `LLMDriverConfiguration.settings` (decrypted per-field via `LLMConfigService::decodeSettings()`).
- Dispatches on `finish_reason: tool_calls`; otherwise returns the text content.

Settings schema (`#[ToolSetting]` attributes on `app/Drivers/OpenAICompatibleDriver.php:18-24`): `api_key` (password), `base_url` (text), `model` (text), `temperature` (text), `max_tokens_output` (text), `context_window` (text), `timeout` (text).

---

## `AnthropicCompatibleDriver`

**File:** `app/Drivers/AnthropicCompatibleDriver.php`  
**Driver name:** `anthropic_compatible`

- Calls `POST {base_url}/v1/messages` using Anthropic's `messages` API. Default `base_url` is `https://api.anthropic.com` (the driver appends `/v1/messages`).
- Uses Anthropic's request format: `system` prompt is a top-level field; `tools` array uses the Anthropic schema (not OpenAI schema).
- Dispatches on `stop_reason: tool_use`; otherwise returns text content blocks.
- Sends `anthropic-version: 2023-06-01` header and `x-api-key` for auth.

Settings schema (`#[ToolSetting]` attributes on `app/Drivers/AnthropicCompatibleDriver.php:18-25`): `api_key` (password), `base_url` (text), `model` (text), `max_tokens_output` (text), `temperature` (text), `context_window` (text), `thinking_budget` (text), `timeout` (text).

---

## `DriverFactory`

**File:** `app/Drivers/DriverFactory.php`

- Calls `LLMConfigService::getEffectiveConfigForAgent($agent)`, which performs three-tier resolution: agent-specific (`agent.llm_driver_config_id`) → user preferred (`user_preferences.preferred_llm_config_id`) → global default (`is_global = true AND is_default = true`). See `app/Services/LLMConfigService.php:362-384`.
- Instantiates the `driver_class` column's FQCN with the decrypted settings plus an HTTP client, logger, and timeout.
- `AnthropicCompatibleDriver` additionally receives `temperature` and `thinking_budget` from settings (`app/Drivers/DriverFactory.php:83-93`).
- If no config is found, falls back to a default `OpenAICompatibleDriver` with `model: 'gpt-4o'`, `base_url: 'https://api.openai.com/v1'`, empty `api_key`, and the configured `llm_timeout` (default 300s).
- The factory itself does not merge plugin drivers; plugin contribution is done at the `llm_driver_classes` container key or by adding a new driver class to the `llm_driver_configurations.driver_class` column.

---

## Dependencies

- `symfony/http-client ^8.0` — HTTP transport for all driver requests (`composer.json:21`).

---

## Tests

- `tests/Unit/Drivers/LLMDriverConfigInterfaceTest.php` — Interface contract for built-in drivers
- `tests/Unit/Drivers/LLMConfigServiceTest.php` — Encryption, getDrivers, schema reflection
- `tests/Unit/Drivers/LLMConfigServicePreferenceTest.php` — User-preferred config resolution
- `tests/Unit/Drivers/DriverFactoryTest.php`
- `tests/Unit/Drivers/OpenAICompatibleDriverTest.php`
- `tests/Unit/Drivers/AnthropicCompatibleDriverTest.php`
- `tests/Unit/Drivers/LLMProviderExceptionTest.php`

Both driver test suites use `Symfony\Component\HttpClient\MockHttpClient` and cover: tool call parsing, text response parsing, error handling, and rate-limit exception propagation.
