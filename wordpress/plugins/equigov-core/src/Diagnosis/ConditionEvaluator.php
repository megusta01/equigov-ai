<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

/**
 * Condições de exibição (show_if). Gramática:
 *   { "question": "k", "op": "equals|not_equals|in|not_empty", "value": ... }
 *   { "all": [cond, ...] }   { "any": [cond, ...] }
 */
final class ConditionEvaluator
{
    public const OPS = ['equals', 'not_equals', 'in', 'not_empty'];
    private const MAX_DEPTH = 4;

    /** @param array<string,mixed> $cond @param array<string,mixed> $answers */
    public static function isVisible(?array $cond, array $answers): bool
    {
        return $cond === null || self::evaluate($cond, $answers);
    }

    /** @param array<string,mixed> $cond @param array<string,mixed> $answers */
    private static function evaluate(array $cond, array $answers): bool
    {
        if (isset($cond['all'])) {
            foreach ($cond['all'] as $c) {
                if (!self::evaluate($c, $answers)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($cond['any'])) {
            foreach ($cond['any'] as $c) {
                if (self::evaluate($c, $answers)) {
                    return true;
                }
            }
            return false;
        }

        $answer = $answers[$cond['question']] ?? null;
        $values = is_array($answer) ? $answer : ($answer === null ? [] : [$answer]);

        return match ($cond['op']) {
            'equals'     => in_array($cond['value'], $values, true),
            'not_equals' => $values !== [] && !in_array($cond['value'], $values, true),
            'in'         => array_intersect($values, (array) $cond['value']) !== [],
            'not_empty'  => $values !== [],
        };
    }

    /**
     * @param array<string,mixed>    $cond
     * @param array<string,Question> $previous perguntas anteriores (as únicas referenciáveis)
     * @return list<string>
     */
    public static function validate(array $cond, array $previous, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['aninhamento excessivo'];
        }

        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $cond)) {
                if (!is_array($cond[$group]) || $cond[$group] === [] || !array_is_list($cond[$group])) {
                    return ["{$group} deve ser lista não vazia"];
                }
                $errors = [];
                foreach ($cond[$group] as $c) {
                    $errors = [...$errors, ...(is_array($c) ? self::validate($c, $previous, $depth + 1) : ['condição deve ser objeto'])];
                }
                return $errors;
            }
        }

        $ref = $cond['question'] ?? null;
        $op  = $cond['op'] ?? null;

        if (!is_string($ref) || !isset($previous[$ref])) {
            return ["question deve referenciar uma pergunta anterior (recebido: " . json_encode($ref) . ')'];
        }
        if (!in_array($op, self::OPS, true)) {
            return ['op deve ser um de: ' . implode(', ', self::OPS)];
        }
        if ($op === 'not_empty') {
            return [];
        }
        if (!array_key_exists('value', $cond)) {
            return ["op {$op} exige value"];
        }

        $expected = $op === 'in' ? (array) $cond['value'] : [$cond['value']];
        foreach ($expected as $v) {
            if (!is_string($v) || !$previous[$ref]->hasOption($v)) {
                return ["value " . json_encode($v) . " não é opção de {$ref}"];
            }
        }
        return [];
    }
}
