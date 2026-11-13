<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Diagnosis\AnswerValidator;
use EquiGov\Core\Diagnosis\QuestionnaireSchema;
use EquiGov\Core\Diagnosis\RiskLevel;
use EquiGov\Core\Diagnosis\ScoringEngine as E;
use PHPUnit\Framework\TestCase;

final class ScoringEngineTest extends TestCase
{
    public function testWeightedScoreIsComputedByHand(): void
    {
        // q1=sim (peso 2, risco 0) + q3 com 1/4 práticas (peso 2, risco 0.75)
        // (2×0 + 2×0.75) / 4 × 100 = 37.5
        $r = E::score(Fixtures::small(), ['q1' => 'sim', 'q3' => ['p2']]);

        self::assertSame(37.5, $r->score);
        self::assertSame(RiskLevel::Moderate, $r->level);
        self::assertSame(0.0, $r->dimensions['a']['score']);
        self::assertSame(75.0, $r->dimensions['b']['score']);
    }

    public function testCriticalAnswerRaisesLevelToAtLeastHigh(): void
    {
        // (2×1 + 1×0.2 + 2×0) / 5 × 100 = 44 → Moderado, mas q1=nao é crítico → Alto
        $r = E::score(Fixtures::small(), ['q1' => 'nao', 'q2' => 'x', 'q3' => ['p1', 'p2', 'p3', 'p4']]);

        self::assertSame(44.0, $r->score);
        self::assertSame(RiskLevel::High, $r->level);
        self::assertSame(1, $r->criticalCount);
    }

    public function testFindingsAreExplainedAndSortedCriticalFirst(): void
    {
        $r = E::score(Fixtures::small(), ['q1' => 'nao', 'q2' => 'y', 'q3' => []]);
        $keys = array_column($r->findings, 'question_key');

        self::assertSame('q1', $keys[0], 'crítico primeiro');
        foreach ($r->findings as $f) {
            foreach (['question_text', 'answer', 'rule', 'message', 'contribution'] as $field) {
                self::assertArrayHasKey($field, $f);
            }
        }
        $q3 = $r->findings[array_search('q3', $keys, true)];
        self::assertSame(['p1', 'p2', 'p3', 'p4'], $q3['missing']);
        self::assertStringContainsString('P1, P2, P3, P4', $q3['message']);
    }

    public function testContributionsAddUpToTheScore(): void
    {
        // Quando todo item com risco > 0 vira achado, as contribuições somam a nota.
        $r = E::score(Fixtures::small(), ['q1' => 'nao', 'q2' => 'y', 'q3' => []]);
        self::assertEqualsWithDelta($r->score, array_sum(array_column($r->findings, 'contribution')), 0.2);
    }

    public function testUnansweredDimensionHasNullScore(): void
    {
        $r = E::score(Fixtures::small(), ['q1' => 'sim']);
        self::assertNull($r->dimensions['b']['score']);
        self::assertFalse($r->dimensions['b']['answered']);
    }

    public function testShippedQuestionnaireWorstCase(): void
    {
        $schema = QuestionnaireSchema::fromJson((string) file_get_contents(Fixtures::SHIPPED));
        $worst  = [];
        foreach ($schema->questions as $q) {
            if ($q->isMulti()) {
                $worst[$q->key] = [];
                continue;
            }
            $risks = array_column($q->options, 'risk');
            $worst[$q->key] = array_keys($q->options)[array_search(max($risks), $risks, true)];
        }
        $visible = AnswerValidator::validateComplete($schema, $worst)['visible'];

        self::assertSame(RiskLevel::Critical, E::score($schema, $visible)->level);
    }

    public function testThresholds(): void
    {
        self::assertSame(RiskLevel::Low, RiskLevel::fromScore(24.9));
        self::assertSame(RiskLevel::Moderate, RiskLevel::fromScore(25));
        self::assertSame(RiskLevel::High, RiskLevel::fromScore(50));
        self::assertSame(RiskLevel::Critical, RiskLevel::fromScore(75));
        self::assertSame(RiskLevel::Critical, RiskLevel::Critical->atLeast(RiskLevel::High));
    }
}
