<?php

declare(strict_types=1);

namespace Spora\OpenApi;

use OpenApi\Annotations as OA;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\Response;
use ReflectionAttribute;
use ReflectionMethod;

/**
 * Lifts per-method OpenAPI metadata off controller actions.
 *
 * Pulled out of {@see RouteToOpenApi} so that orchestrator stays under
 * the SonarCloud S1448 20-method-per-class ceiling. The class owns
 * every `#[OA\Get|Post|Put|...] / #[OA\Parameter] / #[OA\RequestBody]`
 * lookup + the corresponding `requestBody` / `responses` / `parameters`
 * normalisation — the only job of {@see RouteToOpenApi::buildAndAttach()}
 * is to assemble the resulting bag into a per-method `OA\Operation`.
 *
 * The OA library leaves `Undefined` string sentinels on freshly-
 * instantiated `OA\Operation` attributes until its own analyser runs.
 * We never run the analyser, so each property is runtime-checked before
 * landing in the output bag — see {@see extractOperationFields()} for
 * the rationale.
 */
final readonly class HandlerOperationExtractor
{
    /**
     * Public entry: locate the handler's `#[OA\Get|Post|Put|...]`
     * attribute and surface the documented `summary` / `description` /
     * `tags` / `requestBody` / `responses` / `parameters` for merge
     * into the synthesised operation. Returns `[]` when no matching
     * attribute is on the handler — callers then fall back to the
     * synthesised defaults.
     *
     * @param array{0:string, 1:string} $handler
     * @return array{
     *     summary?: string,
     *     description?: string,
     *     tags?: list<string>,
     *     requestBody?: OA\RequestBody,
     *     responses?: array<string, Response>,
     *     parameters?: list<Parameter>,
     * }
     */
    public function operationFromHandler(array $handler, string $method): array
    {
        $op = $this->handlerOperationAttribute($handler, $method);
        if ($op === null) {
            return [];
        }

        return $this->extractOperationFields($op);
    }

    /**
     * Locate the controller method's `#[OA\Get|Post|Put|Patch|Delete|...]`
     * attribute, or `null` when the handler doesn't declare one. The
     * returned object carries the `Undefined` sentinel strings the OA
     * library would normally resolve at analyse time — we never run the
     * analyser here, so {@see extractOperationFields()} filters them.
     *
     * @param array{0:string, 1:string} $handler
     */
    private function handlerOperationAttribute(array $handler, string $method): mixed
    {
        [$class, $methodName] = $handler;
        if (!class_exists($class)) {
            return null;
        }

        $attributeClass = match (strtoupper($method)) {
            'GET' => \OpenApi\Attributes\Get::class,
            'POST' => \OpenApi\Attributes\Post::class,
            'PUT' => \OpenApi\Attributes\Put::class,
            'PATCH' => \OpenApi\Attributes\Patch::class,
            'DELETE' => \OpenApi\Attributes\Delete::class,
            'HEAD' => \OpenApi\Attributes\Head::class,
            'OPTIONS' => \OpenApi\Attributes\Options::class,
            default => null,
        };
        if ($attributeClass === null) {
            return null;
        }

        $attrs = (new ReflectionMethod($class, $methodName))->getAttributes(
            $attributeClass,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * Lift the documented fields (`summary`, `description`, `tags`,
     * `requestBody`, `responses`, `parameters`) onto a fresh output
     * bag. Every property is runtime-checked because the OA library
     * leaves string sentinels on freshly-instantiated attributes until
     * its own analyser runs — and we never run it.
     *
     * @param mixed $op the OA\Operation attribute instance (caller-side
     *                  guard: non-null by contract)
     * @return array{
     *     summary?: string,
     *     description?: string,
     *     tags?: list<string>,
     *     requestBody?: OA\RequestBody,
     *     responses?: array<string, Response>,
     *     parameters?: list<Parameter>,
     * }
     */
    private function extractOperationFields(mixed $op): array
    {
        $out = [];
        if (is_string($op->summary) && $op->summary !== '') {
            $out['summary'] = $op->summary;
        }
        if (is_string($op->description) && $op->description !== '') {
            $out['description'] = $op->description;
        }
        if (is_array($op->tags) && $op->tags !== []) {
            $out['tags'] = $op->tags;
        }
        if ($op->requestBody instanceof OA\RequestBody) {
            $out['requestBody'] = $this->normaliseRequestBody($op->requestBody);
        }
        if (is_array($op->responses) && $op->responses !== []) {
            $out['responses'] = $this->normaliseResponses($op->responses);
        }
        if (is_array($op->parameters) && $op->parameters !== []) {
            $out['parameters'] = array_values($op->parameters);
        }

        return $out;
    }

    /**
     * Convert the `#[OA\RequestBody]` instance into a clean
     * {@see OA\RequestBody} with the unmerged payloads folded into
     * `content[application/json]` entries so the JSON shape
     * serialises properly through `json_encode`.
     */
    public function normaliseRequestBody(OA\RequestBody $requestBody): OA\RequestBody
    {
        $nested = $requestBody->_unmerged;
        $requestBody->_unmerged = [];

        if (!is_array($requestBody->content)) {
            $requestBody->content = [];
        }

        foreach ($nested as $content) {
            if ($content instanceof OA\JsonContent) {
                $requestBody->content['application/json'] = new OA\MediaType([
                    'mediaType' => 'application/json',
                    'schema' => $content,
                ]);
            }
        }

        return $requestBody;
    }

    /**
     * Index the documented `#[OA\Response]` entries by their declared
     * `response` code (string form per the OpenAPI 3.0 spec) so the
     * operation object can map status code → response directly.
     *
     * @param list<Response> $responses
     * @return array<string, Response>
     */
    public function normaliseResponses(array $responses): array
    {
        $out = [];
        foreach ($responses as $response) {
            $key = (string) $response->response;
            $out[$key] = $response;
        }

        return $out;
    }

    /**
     * Read the controller method's `#[OA\Parameter]` attributes for
     * the `parameters` bag. Used as a synthesised default when the
     * handler doesn't declare a full `#[OA\Get|Put|Post...]`
     * attribute.
     *
     * @param array{0:string, 1:string} $handler
     * @return list<Parameter>
     */
    public function parametersFromHandler(array $handler): array
    {
        [$class, $method] = $handler;
        if (!class_exists($class)) {
            return [];
        }

        $attributes = (new ReflectionMethod($class, $method))->getAttributes(
            \OpenApi\Attributes\Parameter::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        return array_map(
            static fn(ReflectionAttribute $attribute): Parameter => $attribute->newInstance(),
            $attributes,
        );
    }

    /**
     * Standalone `#[OA\RequestBody]` lookup — used by
     * {@see RouteToOpenApi::buildAndAttach()} as the fallback for
     * POST / PUT / PATCH handlers that haven't declared a full
     * `#[OA\Post|Put|Patch]` attribute.
     *
     * @param array{0:string, 1:string} $handler
     */
    public function requestBodyFromHandler(array $handler): ?OA\RequestBody
    {
        [$class, $method] = $handler;
        if (!class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionMethod($class, $method))->getAttributes(
            \OpenApi\Attributes\RequestBody::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        if ($attributes === []) {
            return null;
        }

        return $this->normaliseRequestBody($attributes[0]->newInstance());
    }
}
