<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\ActionPlan\PlanWorkflow as W;
use EquiGov\Core\Support\DomainError;
use PHPUnit\Framework\TestCase;

final class PlanWorkflowTest extends TestCase
{
    private const NOW = '2026-11-14T12:00:00Z';

    /** @return list<array<string,mixed>> */
    private function items(): array
    {
        return W::itemsFromAi(['actions' => [
            [
                'title' => 'Central de Libras', 'description' => 'Contratar central de interpretação.', 'priority' => 'alta',
                'deadline_days' => 90, 'indicator' => 'Atendimentos em Libras', 'rationale' => 'art. 26 (S1)',
                'verification' => ['status' => 'grounded'], 'findings' => [['question_key' => 'libras']],
                'sources' => [['document' => 'Decreto 5.626', 'page' => 1]],
            ],
            [
                'title' => 'Ação fraca', 'description' => 'Algo pouco sustentado pela fonte.', 'priority' => 'urgente',
                'verification' => ['status' => 'weak'], 'findings' => [['question_key' => 'canais']], 'sources' => [],
            ],
        ]]);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainError $e) {
            return $e->errorCode;
        }
        self::fail('Era esperado DomainError.');
    }

    public function testItemsStartPendingWithOriginPreserved(): void
    {
        $items = $this->items();
        self::assertSame(['a1', 'a2'], array_column($items, 'id'));
        self::assertSame('pending', $items[0]['decision']);
        self::assertSame('media', $items[1]['origin']['priority'], 'prioridade desconhecida vira média');
        self::assertNull($items[1]['origin']['deadline_days']);
    }

    public function testAcceptCopiesOriginToFinalAndMovesToInReview(): void
    {
        $r = W::decide($this->items(), W::PENDING, 'a1', 'accepted', [], '', 7, self::NOW);
        self::assertSame(W::IN_REVIEW, $r['status']);
        self::assertSame('Central de Libras', $r['items'][0]['final']['title']);
        self::assertSame(7, $r['items'][0]['decided_by']);
    }

    public function testEditKeepsOriginalAiText(): void
    {
        $r = W::decide($this->items(), W::PENDING, 'a1', 'edited', ['title' => 'Central municipal de Libras', 'deadline_days' => 120], '', 7, self::NOW);
        $item = $r['items'][0];
        self::assertSame('Central de Libras', $item['origin']['title']);
        self::assertSame('Central municipal de Libras', $item['final']['title']);
        self::assertSame(120, $item['final']['deadline_days']);
    }

    public function testEditWithoutChangesIsRefused(): void
    {
        self::assertSame('eg_no_changes', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a1', 'edited', ['title' => 'Central de Libras'], '', 7, self::NOW)));
    }

    public function testInvalidEdits(): void
    {
        self::assertSame('eg_invalid_edit', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a1', 'edited', ['title' => ' '], '', 7, self::NOW)));
        self::assertSame('eg_invalid_edit', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a1', 'edited', ['priority' => 'urgente'], '', 7, self::NOW)));
        self::assertSame('eg_invalid_edit', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a1', 'edited', ['deadline_days' => 0], '', 7, self::NOW)));
    }

    public function testWeakSuggestionNeedsJustificationToBeAccepted(): void
    {
        self::assertSame('eg_note_required', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a2', 'accepted', [], 'ok', 7, self::NOW)));
        $r = W::decide($this->items(), W::PENDING, 'a2', 'accepted', [], 'Confirmado com a equipe jurídica.', 7, self::NOW);
        self::assertSame('accepted', $r['items'][1]['decision']);
    }

    public function testRejectionNeedsReason(): void
    {
        self::assertSame('eg_note_required', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a1', 'rejected', [], '', 7, self::NOW)));
    }

    public function testCannotDecideOnLockedPlanOrUnknownItem(): void
    {
        self::assertSame('eg_plan_locked', $this->code(fn () => W::decide($this->items(), W::REVIEWED, 'a1', 'accepted', [], '', 7, self::NOW)));
        self::assertSame('eg_not_found', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a9', 'accepted', [], '', 7, self::NOW)));
        self::assertSame('eg_invalid_decision', $this->code(fn () => W::decide($this->items(), W::PENDING, 'a1', 'talvez', [], '', 7, self::NOW)));
    }

    public function testReviewCompletesOnlyWhenEverythingIsDecided(): void
    {
        $r = W::decide($this->items(), W::PENDING, 'a1', 'accepted', [], '', 7, self::NOW);
        try {
            W::completeReview($r['items'], $r['status']);
            self::fail('Deveria exigir decisão em todos os itens.');
        } catch (DomainError $e) {
            self::assertSame(['a2'], $e->details['pending']);
        }
        $r = W::decide($r['items'], $r['status'], 'a2', 'rejected', [], 'Sem base legal.', 7, self::NOW);
        self::assertSame(W::REVIEWED, W::completeReview($r['items'], $r['status']));
    }

    public function testSegregationOfDuties(): void
    {
        self::assertSame('eg_segregation', $this->code(fn () => W::approve(W::REVIEWED, 7, 7)));
        self::assertSame('eg_plan_not_reviewed', $this->code(fn () => W::approve(W::IN_REVIEW, 7, 8)));
        self::assertSame(W::APPROVED, W::approve(W::REVIEWED, 7, 8));
    }

    public function testRejectPlan(): void
    {
        self::assertSame('eg_reason_required', $this->code(fn () => W::reject(W::REVIEWED, 'não')));
        self::assertSame('eg_plan_locked', $this->code(fn () => W::reject(W::APPROVED, 'Motivo suficiente aqui.')));
        self::assertSame(W::REJECTED, W::reject(W::PENDING, 'Diagnóstico desatualizado.'));
    }

    public function testFinalActionsCarryTraceability(): void
    {
        $r = W::decide($this->items(), W::PENDING, 'a1', 'edited', ['title' => 'Central municipal de Libras'], '', 7, self::NOW);
        $r = W::decide($r['items'], $r['status'], 'a2', 'rejected', [], 'Sem base legal.', 7, self::NOW);
        $final = W::finalActions($r['items']);

        self::assertCount(1, $final);
        self::assertSame('Central municipal de Libras', $final[0]['title']);
        self::assertTrue($final[0]['edited']);
        self::assertSame(['libras'], $final[0]['findings']);
        self::assertSame(['Decreto 5.626, p. 1'], $final[0]['sources']);
    }
}
