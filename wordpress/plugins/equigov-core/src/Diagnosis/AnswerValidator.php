<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

/**
 * Validação no servidor. Nunca confiar no front: ele pode esconder perguntas,
 * mas quem decide o que é visível e obrigatório é este código.
 */
final class AnswerValidator
{
    /**
     * Salvamento parcial (rascunho): valida apenas o que foi enviado.
     *
     * @param array<string,mixed> $answers
     * @return array{answers: array<string,string|list<string>>, errors: array<string,string>}
     */
    public static function validatePartial(QuestionnaireSchema $schema, array $answers): array
    {
        $clean  = [];
        $errors = [];

        foreach ($answers as $key => $value) {
            $q = $schema->question((string) $key);
            if ($q === null) {
                $errors[(string) $key] = 'Pergunta inexistente neste questionário.';
                continue;
            }
            $normalized = self::normalize($q, $value);
            if ($normalized === null) {
                $errors[$q->key] = $q->isMulti()
                    ? 'Envie uma lista de opções válidas, sem repetição.'
                    : 'Opção inválida.';
                continue;
            }
            $clean[$q->key] = $normalized;
        }

        return ['answers' => $clean, 'errors' => $errors];
    }

    /**
     * Envio final: calcula visibilidade em ordem, exige as obrigatórias visíveis
     * e separa respostas de perguntas que ficaram ocultas (serão descartadas).
     *
     * @param array<string,mixed> $stored respostas já normalizadas
     * @return array{visible: array<string,mixed>, visible_keys: list<string>, hidden: list<string>, missing: list<string>}
     */
    public static function validateComplete(QuestionnaireSchema $schema, array $stored): array
    {
        $visible     = [];
        $visibleKeys = [];
        $missing     = [];

        foreach ($schema->questions as $q) {
            // Só respostas visíveis influenciam a visibilidade das próximas.
            if (!ConditionEvaluator::isVisible($q->showIf, $visible)) {
                continue;
            }
            $visibleKeys[] = $q->key;
            if (array_key_exists($q->key, $stored)) {
                $visible[$q->key] = $stored[$q->key];
            } elseif ($q->required) {
                $missing[] = $q->key;
            }
        }

        $hidden = array_values(array_diff(array_keys($stored), array_keys($visible)));

        return ['visible' => $visible, 'visible_keys' => $visibleKeys, 'hidden' => $hidden, 'missing' => $missing];
    }

    /** @return string|list<string>|null */
    private static function normalize(Question $q, mixed $value): string|array|null
    {
        if (!$q->isMulti()) {
            return is_string($value) && $q->hasOption($value) ? $value : null;
        }

        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        foreach ($value as $v) {
            if (!is_string($v) || !$q->hasOption($v)) {
                return null;
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            return null;
        }
        // Ordem canônica = ordem das opções no schema (facilita comparar e auditar).
        return array_values(array_intersect(array_keys($q->options), $value));
    }
}
