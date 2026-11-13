<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Diagnosis\ConditionEvaluator as C;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConditionEvaluatorTest extends TestCase
{
    /** @return iterable<string, array{?array, array, bool}> */
    public static function cases(): iterable
    {
        $answers = ['a' => 'sim', 'm' => ['p1', 'p3'], 'vazio' => []];

        yield 'sem condição é visível'       => [null, $answers, true];
        yield 'equals verdadeiro'            => [['question' => 'a', 'op' => 'equals', 'value' => 'sim'], $answers, true];
        yield 'equals falso'                 => [['question' => 'a', 'op' => 'equals', 'value' => 'nao'], $answers, false];
        yield 'equals em multi'              => [['question' => 'm', 'op' => 'equals', 'value' => 'p3'], $answers, true];
        yield 'not_equals'                   => [['question' => 'a', 'op' => 'not_equals', 'value' => 'nao'], $answers, true];
        yield 'not_equals sem resposta'      => [['question' => 'x', 'op' => 'not_equals', 'value' => 'nao'], $answers, false];
        yield 'in'                           => [['question' => 'a', 'op' => 'in', 'value' => ['nao', 'sim']], $answers, true];
        yield 'not_empty com lista vazia'    => [['question' => 'vazio', 'op' => 'not_empty'], $answers, false];
        yield 'not_empty sem resposta'       => [['question' => 'x', 'op' => 'not_empty'], $answers, false];
        yield 'all'                          => [['all' => [
            ['question' => 'a', 'op' => 'equals', 'value' => 'sim'],
            ['question' => 'm', 'op' => 'equals', 'value' => 'p2'],
        ]], $answers, false];
        yield 'any'                          => [['any' => [
            ['question' => 'a', 'op' => 'equals', 'value' => 'nao'],
            ['question' => 'm', 'op' => 'equals', 'value' => 'p1'],
        ]], $answers, true];
    }

    #[DataProvider('cases')]
    public function testIsVisible(?array $cond, array $answers, bool $expected): void
    {
        self::assertSame($expected, C::isVisible($cond, $answers));
    }

    public function testRejectsDeepNesting(): void
    {
        $cond = ['question' => 'q1', 'op' => 'not_empty'];
        for ($i = 0; $i < 6; $i++) {
            $cond = ['all' => [$cond]];
        }
        self::assertContains('aninhamento excessivo', C::validate($cond, Fixtures::small()->questions));
    }
}
