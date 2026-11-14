<?php

declare(strict_types=1);

namespace EquiGov\Core\ActionPlan;

use EquiGov\Core\Support\DomainError;

/**
 * Regras do plano de ação, sem WordPress (testável).
 *
 *   pending_review ──decisão em item──▶ in_review ──concluir revisão──▶ reviewed ──aprovar──▶ approved
 *         └──────────────────────────────────┴──────────────────────────────┴──rejeitar──▶ rejected
 *
 * Princípios:
 *  - nenhuma sugestão da IA vira plano sem decisão humana item a item;
 *  - o texto original da IA nunca é sobrescrito: edições ficam em "final";
 *  - sugestão com suporte fraco só pode ser aceita com justificativa do revisor;
 *  - separação de funções: quem concluiu a revisão OU decidiu qualquer item não pode aprovar.
 */
final class PlanWorkflow
{
    public const PENDING   = 'pending_review';
    public const IN_REVIEW = 'in_review';
    public const REVIEWED  = 'reviewed';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const STATUSES  = [self::PENDING, self::IN_REVIEW, self::REVIEWED, self::APPROVED, self::REJECTED];
    /** Estados em que o plano ainda não foi finalizado: bloqueiam a geração de outro plano. */
    public const OPEN      = [self::PENDING, self::IN_REVIEW, self::REVIEWED];

    public const DECISIONS  = ['accepted', 'edited', 'rejected'];
    public const PRIORITIES = ['alta', 'media', 'baixa'];

    private const EDITABLE = ['title' => 200, 'description' => 2000, 'indicator' => 500];

    /**
     * Converte a resposta do serviço de IA em itens revisáveis.
     * @param array<string,mixed> $ai
     * @return list<array<string,mixed>>
     */
    public static function itemsFromAi(array $ai): array
    {
        $items = [];
        foreach (array_values((array) ($ai['actions'] ?? [])) as $i => $a) {
            $items[] = [
                'id'           => 'a' . ($i + 1),
                'origin'       => [
                    'title'         => (string) ($a['title'] ?? ''),
                    'description'   => (string) ($a['description'] ?? ''),
                    'priority'      => in_array($a['priority'] ?? '', self::PRIORITIES, true) ? $a['priority'] : 'media',
                    'deadline_days' => isset($a['deadline_days']) ? (int) $a['deadline_days'] : null,
                    'indicator'     => (string) ($a['indicator'] ?? ''),
                    'rationale'     => (string) ($a['rationale'] ?? ''),
                ],
                'verification' => (array) ($a['verification'] ?? []),
                'findings'     => array_values((array) ($a['findings'] ?? [])),
                'sources'      => array_values((array) ($a['sources'] ?? [])),
                'decision'     => 'pending',
                'final'        => null,
                'note'         => '',
                'decided_by'   => null,
                'decided_at'   => null,
            ];
        }
        return $items;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed>       $edits title, description, priority, deadline_days, indicator
     * @return array{items: list<array<string,mixed>>, status: string}
     */
    public static function decide(array $items, string $status, string $itemId, string $decision, array $edits, string $note, int $userId, string $now): array
    {
        if (!in_array($status, [self::PENDING, self::IN_REVIEW], true)) {
            throw new DomainError('eg_plan_locked', 'O plano não está mais em revisão.', 409);
        }
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new DomainError('eg_invalid_decision', 'Decisão inválida.', 422);
        }

        $index = array_search($itemId, array_column($items, 'id'), true);
        if ($index === false) {
            throw new DomainError('eg_not_found', 'Sugestão não encontrada neste plano.', 404);
        }
        $item = $items[$index];
        $note = trim($note);

        if ($decision === 'accepted' && ($item['verification']['status'] ?? '') === 'weak' && mb_strlen($note) < 10) {
            throw new DomainError(
                'eg_note_required',
                'Esta sugestão tem suporte fraco nas fontes. Para aceitá-la, explique o motivo na observação (mín. 10 caracteres).',
                422
            );
        }
        if ($decision === 'rejected' && mb_strlen($note) < 5) {
            throw new DomainError('eg_note_required', 'Informe o motivo da rejeição.', 422);
        }

        $final = null;
        if ($decision === 'accepted') {
            $final = self::finalFrom($item['origin']);
        } elseif ($decision === 'edited') {
            $final = self::applyEdits(self::finalFrom($item['origin']), $edits);
            if ($final === self::finalFrom($item['origin'])) {
                throw new DomainError('eg_no_changes', 'Nenhuma alteração em relação à sugestão original. Use "aceitar".', 422);
            }
        }

        $items[$index] = array_merge($item, [
            'decision'   => $decision,
            'final'      => $final,
            'note'       => mb_substr($note, 0, 1000),
            'decided_by' => $userId,
            'decided_at' => $now,
        ]);

        return ['items' => $items, 'status' => self::IN_REVIEW];
    }

    /** @param list<array<string,mixed>> $items */
    public static function completeReview(array $items, string $status): string
    {
        if (!in_array($status, [self::PENDING, self::IN_REVIEW], true)) {
            throw new DomainError('eg_plan_locked', 'O plano não está em revisão.', 409);
        }
        $pending = array_column(array_filter($items, static fn ($i) => $i['decision'] === 'pending'), 'id');
        if ($pending !== []) {
            throw new DomainError('eg_review_incomplete', 'Há sugestões sem decisão.', 422, ['pending' => array_values($pending)]);
        }
        return self::REVIEWED;
    }

    /** @param list<array<string,mixed>> $items */
    public static function approve(string $status, int $reviewerId, int $approverId, array $items = []): string
    {
        if ($status !== self::REVIEWED) {
            throw new DomainError('eg_plan_not_reviewed', 'Só é possível aprovar um plano com revisão concluída.', 409);
        }
        if (in_array($approverId, self::participants($reviewerId, $items), true)) {
            throw new DomainError('eg_segregation', 'Quem participou da revisão do plano não pode aprová-lo (separação de funções).', 403);
        }
        return self::APPROVED;
    }

    /**
     * Todos que atuaram na revisão: quem concluiu e quem decidiu qualquer item.
     * @param list<array<string,mixed>> $items
     * @return list<int>
     */
    public static function participants(int $reviewerId, array $items): array
    {
        $ids = array_map('intval', array_filter(array_column($items, 'decided_by')));
        if ($reviewerId > 0) {
            $ids[] = $reviewerId;
        }
        return array_values(array_unique($ids));
    }

    public static function reject(string $status, string $reason): string
    {
        if (in_array($status, [self::APPROVED, self::REJECTED], true)) {
            throw new DomainError('eg_plan_locked', 'O plano já foi finalizado.', 409);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainError('eg_reason_required', 'Explique o motivo da rejeição (mín. 10 caracteres).', 422);
        }
        return self::REJECTED;
    }

    /**
     * Ações que compõem o plano aprovado, cada uma com sua origem rastreável.
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function finalActions(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if (in_array($i['decision'], ['accepted', 'edited'], true)) {
                $out[] = $i['final'] + [
                    'item_id'  => $i['id'],
                    'edited'   => $i['decision'] === 'edited',
                    'findings' => array_column($i['findings'], 'question_key'),
                    'sources'  => array_map(static fn ($s) => ($s['document'] ?? '') . ', p. ' . ($s['page'] ?? '?'), $i['sources']),
                ];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $origin */
    private static function finalFrom(array $origin): array
    {
        return [
            'title'         => $origin['title'],
            'description'   => $origin['description'],
            'priority'      => $origin['priority'],
            'deadline_days' => $origin['deadline_days'],
            'indicator'     => $origin['indicator'],
        ];
    }

    /** @param array<string,mixed> $final @param array<string,mixed> $edits */
    private static function applyEdits(array $final, array $edits): array
    {
        foreach (self::EDITABLE as $field => $max) {
            if (array_key_exists($field, $edits) && $edits[$field] !== null) {
                $value = trim((string) $edits[$field]);
                if ($field !== 'indicator' && mb_strlen($value) < 3) {
                    throw new DomainError('eg_invalid_edit', "O campo {$field} não pode ficar vazio.", 422);
                }
                $final[$field] = mb_substr($value, 0, $max);
            }
        }
        if (array_key_exists('priority', $edits) && $edits['priority'] !== null) {
            if (!in_array($edits['priority'], self::PRIORITIES, true)) {
                throw new DomainError('eg_invalid_edit', 'Prioridade inválida.', 422);
            }
            $final['priority'] = $edits['priority'];
        }
        if (array_key_exists('deadline_days', $edits) && $edits['deadline_days'] !== null) {
            $days = (int) $edits['deadline_days'];
            if ($days < 1 || $days > 730) {
                throw new DomainError('eg_invalid_edit', 'Prazo deve ficar entre 1 e 730 dias.', 422);
            }
            $final['deadline_days'] = $days;
        }
        return $final;
    }
}
