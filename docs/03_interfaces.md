# Spora: PHP Interface Contracts

All interfaces live in `app/`. The authoritative source is the source code — this doc describes intent and usage rules.

---

## PHP Attributes (Tool Metadata)

**`app/Tools/Attributes/`** — read via PHP Reflection by the Orchestrator and UI.

| Attribute | Target | Purpose |
|---|---|---|
| `#[Tool(name, description)]` | class | LLM-facing name (snake_case) and description. Required on every tool. |
| `#[OutputTool(requiresApproval: true)]` | class | Class-level approval default for OutputTools. Overridable per-agent via `agent_tools.auto_approve`. |
| `#[ToolParameter(name, type, description, required, default, enum)]` | class (repeatable) | One entry per LLM-facing parameter. Builds the `parameters` JSON Schema for function calling. |
| `#[ToolSetting(key, label, type, description, default, required, scope, options)]` | class (repeatable) | UI-configurable setting stored in `tool_configurations` or `agent_tool_overrides`. Never sent to LLM. `scope: "global"` = shared infra, `scope: "agent"` = per-agent override allowed. |

---

## `InputToolInterface` (`app/Tools/InputToolInterface.php`)

Read-only / generative tools. Executed instantly by the Orchestrator without pausing.

- `execute(array $arguments, int $agentId): ToolResult` — MUST NOT throw; encode errors in `ToolResult`.
- `getParametersSchema(): array` — returns the JSON Schema `parameters` block.

---

## `OutputToolInterface` (`app/Tools/OutputToolInterface.php`)

Write / real-world-action tools. The Orchestrator MUST NOT call `execute()` directly — it pauses the loop and lets `ApprovalResumeHandler` call `execute()` after human approval (or auto-approves immediately).

- `execute(array $arguments, int $agentId): ToolResult` — called only after approval.
- `describeAction(array $arguments): string` — human-readable description for the approval UI.
- `getParametersSchema(): array`

---

## `OrchestratorInterface` (`app/Agents/OrchestratorInterface.php`)

- `start(agentId, userPrompt, maxSteps): Task` — creates Task, dispatches first `TickMessage`.
- `tick(taskId): void` — one loop iteration: load history → LLM call → branch on response type.
- `resume(taskId, approvedArguments): void` — execute approved tool, re-dispatch tick.
- `reject(taskId, reason): void` — inject rejection into history, re-dispatch tick.

---

## `LLMDriverInterface` (`app/Drivers/LLMDriverInterface.php`)

- `complete(LLMRequest $request): LLMResponse`
- `getProviderName(): string` — e.g. `"openai_compatible"`, `"anthropic"`
- `getModelName(): string`

`LLMRequest` / `LLMResponse` value objects in `app/Drivers/ValueObjects/`. Exceptions in `app/Drivers/Exceptions/`.

---

## `PluginInterface` (`app/Plugins/PluginInterface.php`)

- `getName(): string`
- `autoload(): array<string, string>` — PSR-4 namespace → path mappings
- `tools(): list<class-string>` — tool FQCNs to register
- `drivers(): array<string, class-string>` — provider name → driver class
- `recipePaths(): list<string>` — absolute paths to recipe directories
- `register(ContainerBuilder): void` — arbitrary DI bindings
- `schemaVersion(): int` — DB schema version this plugin requires (default: 0)
- `schemaDefinition(): ?SchemaDefinition` — table definitions + upgrade callbacks (default: null)
