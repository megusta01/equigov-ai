<?php

declare(strict_types=1);

namespace EquiGov\Core\ActionPlan;

use EquiGov\Core\Ai\AiClient;
use EquiGov\Core\Audit\AuditLogger;
use EquiGov\Core\Auth\InstitutionScope;
use EquiGov\Core\Diagnosis\DiagnosisService;
use EquiGov\Core\PostTypes\Registrar;
use EquiGov\Core\Support\DomainError;

/**
 * Casos de uso do plano de ação. Cadeia de rastreabilidade gravada no plano:
 * diagnóstico → achados (regra) → fontes (documento, página) → sugestão da IA (verificação)
 * → decisão humana por item (quem, quando, por quê) → aprovação (outra pessoa).
 */
final class ActionPlanService
{
    public function __construct(
        private readonly DiagnosisService $diagnoses,
        private readonly AiClient $ai,
        private readonly AuditLogger $audit,
    ) {
    }

    public function generate(int $diagnosisId, int $userId, ?string $context = null, int $maxActions = 5): int
    {
        $report = $this->diagnoses->report($diagnosisId); // exige diagnóstico concluído

        foreach ($this->forDiagnosis($diagnosisId) as $plan) {
            if (in_array($plan['status'], [PlanWorkflow::PENDING, PlanWorkflow::IN_REVIEW], true)) {
                throw new DomainError('eg_plan_open', 'Já existe um plano em revisão para este diagnóstico. Conclua ou rejeite antes de gerar outro.', 409, ['plan_id' => $plan['id']]);
            }
        }

        $ai = $this->ai->generatePlan($this->payload($diagnosisId, $report), $context, $maxActions);

        $institution = (int) $report['diagnosis']['institution_id'];
        $id = wp_insert_post([
            'post_type'   => Registrar::ACTION_PLAN,
            'post_status' => 'private',
            'post_author' => $userId,
            'post_title'  => 'Plano de ação — ' . $report['diagnosis']['title'],
            'meta_input'  => [
                InstitutionScope::POST_META => $institution,
                '_eg_diagnosis_id'          => $diagnosisId,
                '_eg_plan_status'           => PlanWorkflow::PENDING,
                '_eg_items'                 => $this->json(PlanWorkflow::itemsFromAi($ai)),
                // Resposta completa da IA guardada para auditoria (fontes recuperadas, rejeitadas, métricas).
                '_eg_ai_response'           => $this->json($ai),
                '_eg_context'               => (string) $context,
            ],
        ], true);
        if (is_wp_error($id)) {
            throw new DomainError('eg_db_error', $id->get_error_message(), 500);
        }

        $meta = (array) ($ai['meta'] ?? []);
        $this->audit->log('plan_generated', Registrar::ACTION_PLAN, $id, $institution, [
            'diagnosis_id'   => $diagnosisId,
            'provider'       => $meta['provider'] ?? null,
            'model'          => $meta['model'] ?? null,
            'prompt_version' => $meta['prompt_version'] ?? null,
            'verification'   => $meta['verification'] ?? null,
            'usage'          => $meta['usage'] ?? null,
            'latency_ms'     => $meta['latency_ms'] ?? null,
            'cache_hit'      => $meta['cache_hit'] ?? null,
        ]);
        return $id;
    }

    /** @return array<string,mixed> */
    public function view(int $planId): array
    {
        $post   = $this->load($planId);
        $ai     = $this->decode($post->ID, '_eg_ai_response');
        $items  = $this->items($post->ID);
        $status = $this->status($post->ID);

        return [
            'id'                  => $post->ID,
            'title'               => $post->post_title,
            'diagnosis_id'        => (int) get_post_meta($post->ID, '_eg_diagnosis_id', true),
            'status'              => $status,
            'created_at'          => \EquiGov\Core\Support\Time::gmtToIso($post->post_date_gmt),
            'context'             => (string) get_post_meta($post->ID, '_eg_context', true),
            'items'               => $items,
            'rejected_by_verifier'=> array_values((array) ($ai['rejected'] ?? [])),
            'ai'                  => array_intersect_key((array) ($ai['meta'] ?? []), array_flip(['provider', 'model', 'prompt_version', 'verification', 'usage', 'latency_ms', 'cache_hit'])),
            'reviewed_by'         => $this->person((int) get_post_meta($post->ID, '_eg_reviewed_by', true)),
            'approved_by'         => $this->person((int) get_post_meta($post->ID, '_eg_approved_by', true)),
            'decided_at'          => (string) get_post_meta($post->ID, '_eg_decided_at', true) ?: null,
            'rejection_reason'    => (string) get_post_meta($post->ID, '_eg_rejection_reason', true) ?: null,
            'final_actions'       => $status === PlanWorkflow::APPROVED ? PlanWorkflow::finalActions($items) : [],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function forDiagnosis(int $diagnosisId): array
    {
        $ids = get_posts([
            'post_type'   => Registrar::ACTION_PLAN,
            'post_status' => ['private', 'publish'],
            'meta_key'    => '_eg_diagnosis_id',
            'meta_value'  => $diagnosisId,
            'numberposts' => 50,
            'fields'      => 'ids',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        return array_map(fn (int $id) => [
            'id'         => $id,
            'title'      => get_the_title($id),
            'status'     => $this->status($id),
            'items'      => count($this->items($id)),
            'created_at' => \EquiGov\Core\Support\Time::gmtToIso((string) get_post_field('post_date_gmt', $id)),
        ], $ids);
    }

    /** @param array<string,mixed> $edits */
    public function decideItem(int $planId, string $itemId, int $userId, string $decision, array $edits, string $note): array
    {
        $this->load($planId);
        $result = PlanWorkflow::decide($this->items($planId), $this->status($planId), $itemId, $decision, $edits, $note, $userId, gmdate('c'));

        update_post_meta($planId, '_eg_items', $this->json($result['items']));
        update_post_meta($planId, '_eg_plan_status', $result['status']);

        $item = $result['items'][array_search($itemId, array_column($result['items'], 'id'), true)];
        $this->audit->log('plan_item_decided', Registrar::ACTION_PLAN, $planId, $this->institution($planId), [
            'item'     => $itemId,
            'decision' => $decision,
            'changed'  => $decision === 'edited' ? array_keys(array_diff_assoc(
                array_map('strval', (array) $item['final']),
                array_map('strval', array_intersect_key($item['origin'], (array) $item['final']))
            )) : [],
            'verification' => $item['verification']['status'] ?? null,
        ]);
        return $item;
    }

    public function completeReview(int $planId, int $userId): void
    {
        $this->load($planId);
        $status = PlanWorkflow::completeReview($this->items($planId), $this->status($planId));
        update_post_meta($planId, '_eg_plan_status', $status);
        update_post_meta($planId, '_eg_reviewed_by', $userId);
        $this->audit->log('plan_reviewed', Registrar::ACTION_PLAN, $planId, $this->institution($planId), $this->tally($planId));
    }

    public function approve(int $planId, int $userId): void
    {
        $this->load($planId);
        $status = PlanWorkflow::approve($this->status($planId), (int) get_post_meta($planId, '_eg_reviewed_by', true), $userId);
        update_post_meta($planId, '_eg_plan_status', $status);
        update_post_meta($planId, '_eg_approved_by', $userId);
        update_post_meta($planId, '_eg_decided_at', gmdate('c'));
        $this->audit->log('plan_approved', Registrar::ACTION_PLAN, $planId, $this->institution($planId), $this->tally($planId));
    }

    public function reject(int $planId, int $userId, string $reason): void
    {
        $this->load($planId);
        $status = PlanWorkflow::reject($this->status($planId), $reason);
        update_post_meta($planId, '_eg_plan_status', $status);
        update_post_meta($planId, '_eg_approved_by', $userId);
        update_post_meta($planId, '_eg_decided_at', gmdate('c'));
        update_post_meta($planId, '_eg_rejection_reason', sanitize_textarea_field($reason));
        $this->audit->log('plan_rejected', Registrar::ACTION_PLAN, $planId, $this->institution($planId), ['reason' => mb_substr($reason, 0, 300)]);
    }

    /**
     * Apenas o necessário para a IA: nada de identificação de usuários ou da instituição.
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function payload(int $diagnosisId, array $report): array
    {
        $cut = static fn ($v, int $n) => mb_substr((string) $v, 0, $n);
        return [
            'id'         => $diagnosisId,
            'score'      => (float) $report['score'],
            'risk_level' => (string) $report['risk_level'],
            'findings'   => array_slice(array_map(static fn (array $f) => [
                'question_key'  => $f['question_key'],
                'question_text' => $cut($f['question_text'], 500),
                'answer'        => $cut($f['answer'] ?? '', 300),
                'rule'          => $cut($f['rule'] ?? '', 300),
                'message'       => $cut($f['message'] ?? '', 1500),
                'reference'     => isset($f['reference']) ? $cut($f['reference'], 300) : null,
                'dimension'     => (string) ($f['dimension'] ?? ''),
                'critical'      => (bool) ($f['critical'] ?? false),
                'contribution'  => (float) ($f['contribution'] ?? 0),
            ], (array) $report['findings']), 0, 50),
        ];
    }

    /** @return array<string,int> */
    private function tally(int $planId): array
    {
        $counts = array_count_values(array_column($this->items($planId), 'decision'));
        return ['accepted' => $counts['accepted'] ?? 0, 'edited' => $counts['edited'] ?? 0, 'rejected' => $counts['rejected'] ?? 0];
    }

    private function load(int $id): \WP_Post
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== Registrar::ACTION_PLAN) {
            throw DomainError::notFound('Plano de ação');
        }
        return $post;
    }

    /** @return list<array<string,mixed>> */
    private function items(int $id): array
    {
        return array_values($this->decode($id, '_eg_items'));
    }

    private function status(int $id): string
    {
        return (string) get_post_meta($id, '_eg_plan_status', true) ?: PlanWorkflow::PENDING;
    }

    private function institution(int $id): int
    {
        return (int) get_post_meta($id, InstitutionScope::POST_META, true);
    }

    /** @return array<string,mixed> */
    private function decode(int $id, string $key): array
    {
        $v = json_decode((string) get_post_meta($id, $key, true), true);
        return is_array($v) ? $v : [];
    }

    /** JSON pronto para update_post_meta (que remove barras: por isso wp_slash). */
    private function json(array $value): string
    {
        return wp_slash(wp_json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /** @return array{id:int,name:string}|null */
    private function person(int $userId): ?array
    {
        $u = $userId ? get_userdata($userId) : false;
        return $u ? ['id' => $u->ID, 'name' => $u->display_name] : null;
    }
}
