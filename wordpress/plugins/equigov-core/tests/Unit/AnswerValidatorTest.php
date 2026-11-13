<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Diagnosis\AnswerValidator as V;
use PHPUnit\Framework\TestCase;

final class AnswerValidatorTest extends TestCase
{
    public function testPartialRejectsUnknownQuestionsAndInvalidValues(): void
    {
        $r = V::validatePartial(Fixtures::small(), [
            'q1' => 'talvez',            // opção inexistente
            'q3' => ['p1', 'p1'],        // repetida
            'zz' => 'x',                 // pergunta inexistente
        ]);
        self::assertSame(['q1', 'q3', 'zz'], array_keys($r['errors']));
        self::assertSame([], $r['answers']);
    }

    public function testPartialRejectsWrongShapes(): void
    {
        $r = V::validatePartial(Fixtures::small(), ['q1' => ['sim'], 'q3' => 'p1']);
        self::assertCount(2, $r['errors']);
    }

    public function testMultiIsNormalizedToSchemaOrder(): void
    {
        $r = V::validatePartial(Fixtures::small(), ['q3' => ['p4', 'p1']]);
        self::assertSame(['p1', 'p4'], $r['answers']['q3']);
    }

    public function testEmptyMultiIsAValidAnswer(): void
    {
        $r = V::validatePartial(Fixtures::small(), ['q3' => []]);
        self::assertSame([], $r['errors']);
        self::assertSame([], $r['answers']['q3']);
    }

    public function testConditionalQuestionBecomesRequiredOnlyWhenVisible(): void
    {
        $hidden = V::validateComplete(Fixtures::small(), ['q1' => 'sim', 'q3' => []]);
        self::assertSame([], $hidden['missing']);
        self::assertSame(['q1', 'q3'], $hidden['visible_keys']);

        $shown = V::validateComplete(Fixtures::small(), ['q1' => 'nao', 'q3' => []]);
        self::assertSame(['q2'], $shown['missing']);
    }

    public function testAnswersToHiddenQuestionsAreSeparated(): void
    {
        $r = V::validateComplete(Fixtures::small(), ['q1' => 'sim', 'q2' => 'y', 'q3' => ['p1']]);
        self::assertSame(['q2'], $r['hidden']);
        self::assertArrayNotHasKey('q2', $r['visible']);
    }
}
