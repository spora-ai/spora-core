<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Splits the operator-submitted `{tool_call_id, answers: [...]}` payload
 * into the `by_header` map used downstream by {@see AnswerQuestionRequestValidator}.
 *
 * Kept as a separate class so the validator itself stays under the
 * per-class method-count limit while still breaking the per-answer
 * validation chain far enough to satisfy the per-method return-count
 * rule.
 */
final class AnswerPayloadParser
{
    private const ERR_ANSWERS_LIST = 'answers must be a non-empty array.';

    private const ERR_ANSWER_SHAPE = 'Every answer must be an object.';

    private const ERR_HEADER_REQUIRED = "Every answer requires a 'header' string.";

    /**
     * @param array<string, mixed> $body
     * @return array<string, array{selections: list<string>, free_text: ?string}>|JsonResponse
     */
    public function extractAnswerMap(array $body): array|JsonResponse
    {
        $rawAnswers = $body['answers'] ?? null;
        if (!is_array($rawAnswers) || !array_is_list($rawAnswers) || $rawAnswers === []) {
            return $this->error(self::ERR_ANSWERS_LIST);
        }
        return $this->collectAnswersByHeader($rawAnswers);
    }

    /**
     * @param list<mixed> $rawAnswers
     * @return array<string, array{selections: list<string>, free_text: ?string}>|JsonResponse
     */
    private function collectAnswersByHeader(array $rawAnswers): array|JsonResponse
    {
        $byHeader = [];
        foreach ($rawAnswers as $item) {
            $parsed = $this->parseSingleAnswer($item);
            if ($parsed instanceof JsonResponse) {
                return $parsed;
            }
            [$header, $entry] = $parsed;
            if (isset($byHeader[$header])) {
                return $this->error("Duplicate answer for header '{$header}'.");
            }
            $byHeader[$header] = $entry;
        }
        return $byHeader;
    }

    /**
     * @param mixed $item
     * @return array{0: string, 1: array{selections: list<string>, free_text: ?string}}|JsonResponse
     */
    private function parseSingleAnswer(mixed $item): array|JsonResponse
    {
        if (!is_array($item)) {
            return $this->error(self::ERR_ANSWER_SHAPE);
        }
        $shapeError = $this->validateAnswerShape($item);
        if ($shapeError !== null) {
            return $shapeError;
        }
        return $this->buildAnswerEntry($item);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function validateAnswerShape(array $item): ?JsonResponse
    {
        $header = trim((string) ($item['header'] ?? ''));
        if ($header === '') {
            return $this->error(self::ERR_HEADER_REQUIRED);
        }
        $selectionsRaw = $item['selections'] ?? null;
        if (!is_array($selectionsRaw) || !array_is_list($selectionsRaw)) {
            return $this->error("Answer for '{$header}' must include a 'selections' array.");
        }
        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{0: string, 1: array{selections: list<string>, free_text: ?string}}
     */
    private function buildAnswerEntry(array $item): array
    {
        $header = trim((string) $item['header']);
        $selections = array_map(static fn($s): string => (string) $s, $item['selections']);
        $freeTextRaw = $item['free_text'] ?? null;
        $freeText = is_string($freeTextRaw) && $freeTextRaw !== '' ? $freeTextRaw : null;
        return [$header, ['selections' => $selections, 'free_text' => $freeText]];
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'VALIDATION_ERROR', 'message' => $message]],
            \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
