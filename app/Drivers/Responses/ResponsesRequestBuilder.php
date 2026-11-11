<?php

declare(strict_types=1);

namespace Spora\Drivers\Responses;

use Spora\Drivers\ModelFamily;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMRequest;
use stdClass;

/**
 * Serialises an {@see LLMRequest} into the OpenAI `/v1/responses` wire
 * shape. Pure transformation: no I/O, no driver state — the driver owns
 * the HTTP call.
 *
 * The Responses API is not a dialect of Chat Completions. The system
 * prompt is a top-level `instructions` string, the tool definitions are
 * internally tagged, a tool result is an input item of its own, and a
 * reasoning item is a sibling of the message it belongs to rather than
 * part of that message's content.
 */
final class ResponsesRequestBuilder
{
    /**
     * The function-name grammar the API enforces on the request side:
     * letters, digits, underscore and hyphen.
     */
    private const TOOL_NAME_PATTERN = '/[^a-zA-Z0-9_-]/';

    /**
     * The keywords a schema may carry and still be strict-compatible.
     *
     * Strict mode rejects the JSON-Schema keywords it cannot enforce, so
     * anything outside this set disqualifies the schema.
     */
    private const STRICT_KEYWORDS = ['type', 'enum', 'description', 'items', 'properties', 'required', 'additionalProperties'];

    /**
     * The types strict mode understands.
     */
    private const STRICT_TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'];

    /**
     * The smallest output the API accepts.
     */
    private const MINIMUM_OUTPUT_TOKENS = 16;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly bool $store,
    ) {}

    /**
     * @return array<string, string>
     */
    public function buildHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return $headers;
    }

    /**
     * @param string|null $effort the provider's effort value, or null to send none
     * @return array<string, mixed>
     */
    public function buildBody(LLMRequest $request, ?string $effort = null): array
    {
        $body = [
            'model' => $this->model,
            'instructions' => $request->systemPrompt,
            'input' => $this->convertMessages($request->messages),
        ];

        // The tool definitions and the choice between them go together,
        // and only when there is a tool to choose: an empty tool list
        // with a `tool_choice` is at best a no-op.
        $tools = $this->convertTools($request->tools);
        if ($tools !== []) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        $body['max_output_tokens'] = max(self::MINIMUM_OUTPUT_TOKENS, $request->maxTokens);
        $body['store'] = $this->store;

        if (ModelFamily::isOpenAIReasoningModel($this->model)) {
            // The encrypted reasoning content is what makes a reasoning
            // item replayable in the next turn. The spec says it is
            // populated by default, and documents this `include` value
            // for the stateless case — `store` off, or a project with
            // zero data retention enabled — where the item comes back
            // with the blob and not by id. A model that does not reason
            // has no reasoning item to include.
            $body['include'] = ['reasoning.encrypted_content'];
        }

        if ($effort === null) {
            return $body;
        }

        if (ModelFamily::openAISupportsConfigurationUpdate($this->model)) {
            // The update changes the effort for the responses that follow
            // it without rewriting the request-level `reasoning.effort`,
            // so the cached prompt prefix survives. It has to be the last
            // input item: it applies to the response generated after it.
            $body['input'][] = [
                'type' => 'configuration_update',
                'reasoning' => ['effort' => $effort],
            ];

            return $body;
        }

        $body['reasoning'] = ['effort' => $effort];

        return $body;
    }

    /**
     * The wire names of the request's tools, keyed by the name the
     * provider echoes back in a `function_call` item.
     *
     * The model answers with the name it was given, so the mapping back
     * to the name Spora knows is what lets the tool executor find the
     * tool.
     *
     * @param  list<array<string, mixed>> $tools
     * @return array<string, string>
     */
    public static function toolNameMap(array $tools): array
    {
        $map = [];

        foreach ($tools as $tool) {
            if (($tool['type'] ?? null) !== 'function') {
                continue;
            }
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];
            $name = (string) ($function['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $map[self::sanitizeToolName($name)] = $name;
        }

        return $map;
    }

    /**
     * The wire name of a tool, in the grammar the API enforces.
     *
     * A plugin tool's name is qualified with the plugin slug and a colon
     * separator, and the colon is outside the grammar. The separator is
     * the only character that can be outside it — the slug matches
     * `/^[a-z0-9][a-z0-9_-]*$/` and the tool name `/^[a-z][a-z0-9_]*$/` —
     * so replacing it with a hyphen keeps the names distinct: the tool
     * name carries no hyphen, which makes the separator the last hyphen
     * of the sanitised name.
     */
    public static function sanitizeToolName(string $name): string
    {
        return (string) preg_replace(self::TOOL_NAME_PATTERN, '-', $name);
    }

    /**
     * @param  list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function convertMessages(array $messages): array
    {
        $items = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');

            if ($role === 'tool') {
                $items[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($message['tool_call_id'] ?? ''),
                    'output' => $this->outputText($message['content'] ?? null),
                ];
                continue;
            }

            $content = $message['content'] ?? null;

            // The reasoning item travels as a sibling of the message it
            // belongs to: the provider correlates it by id, and it has
            // to precede the message and the tool calls of the same turn.
            foreach ($this->reasoningItems($content) as $item) {
                $items[] = $item;
            }

            $parts = $this->contentParts($content, $role);
            if ($parts !== []) {
                $items[] = [
                    'type' => 'message',
                    'role' => $role,
                    'content' => $parts,
                ];
            }

            foreach ($this->functionCallItems($message['tool_calls'] ?? []) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private function convertTools(array $tools): array
    {
        $out = [];

        foreach ($tools as $tool) {
            if (($tool['type'] ?? null) !== 'function') {
                continue;
            }
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];
            $name = (string) ($function['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $parameters = is_array($function['parameters'] ?? null) ? $function['parameters'] : [];

            $out[] = [
                'type' => 'function',
                'name' => self::sanitizeToolName($name),
                'description' => (string) ($function['description'] ?? ''),
                // The schema is an object on the wire, and an empty PHP
                // array would encode as a JSON array.
                'parameters' => $parameters === [] ? new stdClass() : $parameters,
                'strict' => $this->isStrictCompatible($parameters),
            ];
        }

        return $out;
    }

    /**
     * Whether the schema already satisfies the API's strict-mode rules.
     *
     * Responses *attempts* strict mode when the flag is omitted and falls
     * back silently when it cannot, so the flag is always sent: `true`
     * only for a schema that is already strict-compatible, `false` for
     * the rest. The Chat Completions driver sends no such flag — it
     * names the schema and lets the provider decide.
     *
     * @param array<string, mixed> $schema
     */
    private function isStrictCompatible(array $schema): bool
    {
        if (($schema['type'] ?? null) !== 'object') {
            return false;
        }

        return $this->isStrictNode($schema);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isStrictNode(array $node): bool
    {
        $types = $this->strictTypes($node);

        if ($types === null) {
            return false;
        }

        $items = $node['items'] ?? null;

        return match (true) {
            in_array('object', $types, true) => $this->isStrictObject($node),
            in_array('array', $types, true) => is_array($items) && $this->isStrictNode($items),
            default => true,
        };
    }

    /**
     * The types the node declares, or null when the node is not one strict
     * mode can enforce.
     *
     * A keyword outside the strict set disqualifies the node as a whole, so
     * it is refused here rather than by the caller.
     *
     * @param array<string, mixed> $node
     * @return list<string>|null
     */
    private function strictTypes(array $node): ?array
    {
        foreach (array_keys($node) as $keyword) {
            if (!in_array($keyword, self::STRICT_KEYWORDS, true)) {
                return null;
            }
        }

        $type = $node['type'] ?? null;
        $entries = is_array($type) ? $type : [$type];

        $types = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || !in_array($entry, self::STRICT_TYPES, true)) {
                return null;
            }
            $types[] = $entry;
        }

        return $types;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isStrictObject(array $node): bool
    {
        $properties = $node['properties'] ?? null;
        $required = $node['required'] ?? null;

        if (($node['additionalProperties'] ?? null) !== false
            || !is_array($properties)
            || !is_array($required)
        ) {
            return false;
        }

        return array_all(
            $properties,
            fn(mixed $property, mixed $name): bool => in_array($name, $required, true)
                && is_array($property)
                && $this->isStrictNode($property),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentParts(mixed $content, string $role): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['type' => $this->textPartType($role), 'text' => $content]];
        }

        if (!is_array($content)) {
            return [];
        }

        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            $part = $this->contentPart($block, $role);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function contentPart(array $block, string $role): ?array
    {
        return match ($block['type'] ?? null) {
            ContentBlock::TYPE_TEXT => ['type' => $this->textPartType($role), 'text' => (string) ($block['text'] ?? '')],
            // The assistant role takes no image: its content is the
            // model's own output, and the API validates that content
            // against `OutputMessageContent` — `output_text` and
            // `refusal`.
            ContentBlock::TYPE_IMAGE => $role === 'assistant' ? null : $this->imagePart($block),
            // A thinking or redacted-thinking block belongs to another
            // provider's chain, and a tool-use block is carried by the
            // `function_call` items instead.
            default => null,
        };
    }

    /**
     * The type of the text part the role takes.
     *
     * An assistant turn is replayed as the model's own output: the API
     * validates its content against `OutputMessageContent`, which is
     * `output_text` and `refusal` — `input_text` there is a 400. OpenAI's
     * "Migrate to the Responses API" guide makes the same split for the
     * roles a conversation carries, `input_text` for the user and
     * `output_text` for the assistant.
     *
     * The published OpenAPI schema does not list `output_text` in the
     * `InputContent` union, so the schema alone reads as if the
     * assistant role took `input_text`; it is the narrower statement of
     * the two, and it does not describe what the API accepts.
     */
    private function textPartType(string $role): string
    {
        return $role === 'assistant' ? 'output_text' : 'input_text';
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function imagePart(array $block): ?array
    {
        $url = null;

        if (isset($block['base64']) && is_string($block['base64']) && $block['base64'] !== '' && isset($block['mediaType'])) {
            $url = 'data:' . $block['mediaType'] . ';base64,' . $block['base64'];
        } elseif (isset($block['url']) && is_string($block['url']) && $block['url'] !== '') {
            $url = $block['url'];
        }

        if ($url === null) {
            return null;
        }

        // The API takes the image URL as a plain string with the detail
        // as its sibling, where Chat Completions nests both.
        return ['type' => 'input_image', 'image_url' => $url, 'detail' => 'auto'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reasoningItems(mixed $content): array
    {
        if (!is_array($content)) {
            return [];
        }

        $items = [];
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== ContentBlock::TYPE_REASONING) {
                continue;
            }
            $item = $this->reasoningItem($block);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function reasoningItem(array $block): ?array
    {
        $metadata = is_array($block['metadata'] ?? null) ? $block['metadata'] : [];
        $id = (string) ($metadata['id'] ?? '');
        if ($id === '') {
            // The provider correlates the item by its id and rejects an
            // item without one, so a block that lost it cannot be
            // replayed.
            return null;
        }

        $blob = isset($block['data']) && is_string($block['data']) ? $block['data'] : '';

        if ($blob === '' && ! $this->store) {
            // There is nothing to resolve the item by: the response was
            // not stored, so the id alone resolves to nothing and the
            // replay fails. A lost chain of thought is better than a
            // failed request.
            return null;
        }

        $item = [
            'type' => 'reasoning',
            'id' => $id,
            'summary' => is_array($metadata['summary'] ?? null) ? $metadata['summary'] : [],
        ];

        if ($blob !== '') {
            // The encrypted blob is what the provider decrypts to restore
            // the chain of thought, and it resolves the item whether or
            // not the provider stored it — so it goes out whatever
            // `store` says.
            $item['encrypted_content'] = $blob;
        }

        return $item;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function functionCallItems(mixed $toolCalls): array
    {
        if (!is_array($toolCalls)) {
            return [];
        }

        $items = [];
        foreach ($toolCalls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $name = (string) ($function['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $items[] = [
                'type' => 'function_call',
                'call_id' => (string) ($call['id'] ?? ''),
                'name' => self::sanitizeToolName($name),
                'arguments' => $this->arguments($function['arguments'] ?? '{}'),
            ];
        }

        return $items;
    }

    private function arguments(mixed $raw): string
    {
        if (is_array($raw)) {
            return json_encode($raw === [] ? new stdClass() : $raw, JSON_THROW_ON_ERROR);
        }

        $text = (string) $raw;

        return $text === '' ? '{}' : $text;
    }

    private function outputText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === ContentBlock::TYPE_TEXT) {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }
}
