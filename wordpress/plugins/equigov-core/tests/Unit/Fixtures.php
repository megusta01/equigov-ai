<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Diagnosis\QuestionnaireSchema;

final class Fixtures
{
    public const SHIPPED = __DIR__ . '/../../data/questionnaires/acesso-servicos-v1.json';

    /** Questionário mínimo: q1 (single, peso 2), q2 só aparece se q1 = nao, q3 multi com 4 práticas. */
    public static function small(): QuestionnaireSchema
    {
        return QuestionnaireSchema::fromArray(self::smallArray());
    }

    /** @return array<string,mixed> */
    public static function smallArray(): array
    {
        return [
            'key'        => 'teste',
            'version'    => 1,
            'title'      => 'Teste',
            'dimensions' => ['a' => 'Dimensão A', 'b' => 'Dimensão B'],
            'questions'  => [
                [
                    'key' => 'q1', 'dimension' => 'a', 'type' => 'single_choice', 'weight' => 2, 'text' => 'Q1',
                    'options' => [
                        ['value' => 'sim', 'label' => 'Sim', 'risk' => 0],
                        ['value' => 'nao', 'label' => 'Não', 'risk' => 1, 'finding' => 'Falta Q1', 'critical' => true],
                    ],
                ],
                [
                    'key' => 'q2', 'dimension' => 'a', 'type' => 'single_choice', 'weight' => 1, 'text' => 'Q2',
                    'show_if' => ['question' => 'q1', 'op' => 'equals', 'value' => 'nao'],
                    'options' => [
                        ['value' => 'x', 'label' => 'X', 'risk' => 0.2],
                        ['value' => 'y', 'label' => 'Y', 'risk' => 0.8],
                    ],
                ],
                [
                    'key' => 'q3', 'dimension' => 'b', 'type' => 'multi_choice', 'weight' => 2, 'text' => 'Q3',
                    'finding' => 'Práticas ausentes',
                    'options' => [
                        ['value' => 'p1', 'label' => 'P1'], ['value' => 'p2', 'label' => 'P2'],
                        ['value' => 'p3', 'label' => 'P3'], ['value' => 'p4', 'label' => 'P4'],
                    ],
                ],
            ],
        ];
    }
}
