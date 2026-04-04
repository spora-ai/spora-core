# LLM Drivers

Spora's LLM layer is built around `LLMDriverInterface`. Drivers are resolved per-request by `DriverFactory` based on the agent's `llm_provider` column.

---

## `OpenAICompatibleDriver`

**File:** `app/Drivers/OpenAICompatibleDriver.php`  
**Provider name:** `openai_compatible` (default value for `agents.llm_provider`)

- Calls `POST {base_url}/chat/completions` using the standard OpenAI chat completions format.
- `base_url` defaults to `https://api.openai.com/v1`; can be overridden for Ollama, Groq, LM Studio, Azure, etc.
- Reads `api_key` and `model` from `ToolConfigService`, falling back to the Agent row.
- Parses `finish_reason: tool_calls` for tool dispatch; `finish_reason: stop` for text responses.

---

## `AnthropicCompatibleDriver`

**File:** `app/Drivers/AnthropicCompatibleDriver.php`  
**Provider name:** `anthropic`

- Calls `POST {base_url}` using Anthropic's `messages` API. Default `base_url` is `https://api.anthropic.com/v1/messages`.
- Uses Anthropic's request format: `system` prompt is a top-level field; `tools` array uses the Anthropic schema (not OpenAI schema).
- Parses `stop_reason: tool_use` to extract `tool_use` content blocks; `stop_reason: end_turn` to extract `text` blocks.

---

## `DriverFactory`

**File:** `app/Drivers/DriverFactory.php`

- Resolves `agent.llm_provider` to a driver instance.
- Merges plugin-registered drivers, allowing plugins to contribute new providers at boot.
- Bound as `LLMDriverInterface` in `container.php`; resolved per-request from the Agent row.

---

## Dependencies

- `symfony/http-client ^7.0` — HTTP transport for all driver requests.

---

## Tests

- `tests/Unit/OpenAICompatibleDriverTest.php`
- `tests/Unit/AnthropicCompatibleDriverTest.php`

Both test suites use mocked HTTP responses and cover: tool call parsing, text response parsing, error handling, and rate-limit exception propagation.
