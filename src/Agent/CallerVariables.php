<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:33:41 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Checks the variables a caller supplies for the instructions of an agent
 * version against the version's `startSchema`, in this order (each step
 * reports all its findings in one 422 detail, joined with `; `):
 *
 *  1. a system value name ({@see SYSTEM_VALUES}) cannot be supplied;
 *     a value must be a string, number or boolean;
 *  2. without a `startSchema` no variable is declared; with
 *     `additionalProperties: false`, a name outside `properties` and
 *     `patternProperties` is not declared either;
 *  3. the variables object is validated against the `startSchema` with
 *     opis/json-schema (default draft 2020-12, as at publish), each error
 *     reported as `variables/<pointer>: <message>`.
 *
 * Optional variables are not filled with schema defaults: a placeholder of
 * a variable that is not supplied stays unfilled and is rejected by
 * {@see InstructionTemplate::render()}.
 */
final class CallerVariables
{
    /** Placeholder names filled by the mapper, never by the caller. */
    public const array SYSTEM_VALUES = ['date', 'language', 'agentName'];

    private const int MAX_ERRORS = 20;

    /**
     * @param array<array-key, mixed>   $variables   as decoded from the request
     * @param array<string, mixed>|null $startSchema the version's start schema
     *
     * @return array<string, string> name => value as substituted (`true`/`false`, numbers cast to string)
     *
     * @throws UnprocessableEntityHttpException
     */
    public static function validate(array $variables, ?array $startSchema): array
    {
        $errors = [];
        foreach ($variables as $name => $value) {
            $name = (string) $name;
            if (\in_array($name, self::SYSTEM_VALUES, true)) {
                $errors[] = \sprintf('variables/%1$s: "%1$s" is a system value and cannot be supplied.', $name);
            } elseif (!\is_scalar($value)) {
                $errors[] = \sprintf('variables/%s: must be a string, number or boolean.', $name);
            }
        }
        self::throwIfAny($errors);

        foreach (array_keys($variables) as $name) {
            if (!self::isDeclared((string) $name, $startSchema)) {
                $errors[] = \sprintf('variables/%s: not declared by the agent version.', $name);
            }
        }
        self::throwIfAny($errors);

        if (null !== $startSchema) {
            $object = new \stdClass();
            foreach ($variables as $name => $value) {
                $object->{(string) $name} = $value;
            }

            $validator = new Validator();
            $validator->setMaxErrors(self::MAX_ERRORS);
            $result = $validator->validate($object, AgentConfiguration::toJsonValue($startSchema));
            if (!$result->isValid()) {
                foreach (new ErrorFormatter()->format($result->error(), false) as $pointer => $message) {
                    $errors[] = \sprintf('%s: %s', '/' === $pointer || '' === $pointer ? 'variables' : 'variables'.$pointer, $message);
                }
            }
            self::throwIfAny($errors);
        }

        $values = [];
        foreach ($variables as $name => $value) {
            $values[(string) $name] = match (true) {
                true === $value => 'true',
                false === $value => 'false',
                default => (string) $value,
            };
        }

        return $values;
    }

    /**
     * @param array<string, mixed>|null $startSchema
     */
    private static function isDeclared(string $name, ?array $startSchema): bool
    {
        if (null === $startSchema) {
            return false;
        }
        if (false !== ($startSchema['additionalProperties'] ?? true)) {
            return true;
        }
        if (\is_array($startSchema['properties'] ?? null) && \array_key_exists($name, $startSchema['properties'])) {
            return true;
        }
        foreach (array_keys(\is_array($startSchema['patternProperties'] ?? null) ? $startSchema['patternProperties'] : []) as $pattern) {
            if (1 === @preg_match('/'.str_replace('/', '\/', (string) $pattern).'/u', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $errors
     */
    private static function throwIfAny(array $errors): void
    {
        if ([] !== $errors) {
            throw new UnprocessableEntityHttpException(implode('; ', $errors));
        }
    }
}
