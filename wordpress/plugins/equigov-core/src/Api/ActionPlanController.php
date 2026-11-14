<?php

declare(strict_types=1);

namespace EquiGov\Core\Api;

use EquiGov\Core\ActionPlan\ActionPlanService;
use EquiGov\Core\ActionPlan\PlanWorkflow;
use EquiGov\Core\Auth\Roles;
use EquiGov\Core\PostTypes\Registrar;

/**
 *   POST  /diagnoses/{id}/action-plans          gestor gera sugestões (chama o serviço de IA)
 *   GET   /diagnoses/{id}/action-plans          planos do diagnóstico
 *   GET   /action-plans/{id}                    plano com itens, fontes e métricas da IA
 *   PATCH /action-plans/{id}/items/{item}       revisor decide: accepted | edited | rejected
 *   POST  /action-plans/{id}/complete-review    revisor conclui
 *   POST  /action-plans/{id}/approve            gestor aprova (não pode ser quem revisou)
 *   POST  /action-plans/{id}/reject             gestor rejeita com motivo
 */
final class ActionPlanController
{
    public function __construct(private readonly ActionPlanService $plans)
    {
    }

    public function register(): void
    {
        $ns   = HealthController::NAMESPACE;
        $id   = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        $plan = fn (array $caps) => fn (\WP_REST_Request $r) => Permissions::post((int) $r['id'], Registrar::ACTION_PLAN, null, $caps, 'Plano de ação');

        register_rest_route($ns, '/diagnoses/(?P<id>\d+)/action-plans', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => fn (\WP_REST_Request $r) => new \WP_REST_Response($this->plans->forDiagnosis((int) $r['id'])),
                'permission_callback' => fn (\WP_REST_Request $r) => Permissions::post((int) $r['id'], Registrar::DIAGNOSIS, null, [], 'Diagnóstico'),
                'args'                => $id,
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn (\WP_REST_Request $r) => Permissions::run(function () use ($r) {
                    $planId = $this->plans->generate((int) $r['id'], get_current_user_id(), (string) ($r['context'] ?? ''), (int) $r['max_actions']);
                    return new \WP_REST_Response($this->plans->view($planId), 201);
                }),
                'permission_callback' => fn (\WP_REST_Request $r) => Permissions::post((int) $r['id'], Registrar::DIAGNOSIS, null, [Roles::MANAGE_DIAGNOSES], 'Diagnóstico'),
                'args'                => $id + [
                    'context'     => ['type' => 'string', 'maxLength' => 1000, 'sanitize_callback' => 'sanitize_textarea_field'],
                    'max_actions' => ['type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 10],
                ],
            ],
        ]);

        register_rest_route($ns, '/action-plans/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => fn (\WP_REST_Request $r) => Permissions::run(fn () => new \WP_REST_Response($this->plans->view((int) $r['id']))),
            'permission_callback' => $plan([]),
            'args'                => $id,
        ]);

        register_rest_route($ns, '/action-plans/(?P<id>\d+)/items/(?P<item>a\d{1,3})', [
            'methods'             => 'PATCH',
            'callback'            => fn (\WP_REST_Request $r) => Permissions::run(fn () => new \WP_REST_Response(
                $this->plans->decideItem(
                    (int) $r['id'],
                    (string) $r['item'],
                    get_current_user_id(),
                    (string) $r['decision'],
                    array_intersect_key($r->get_params(), array_flip(['title', 'description', 'priority', 'deadline_days', 'indicator'])),
                    (string) ($r['note'] ?? '')
                )
            )),
            'permission_callback' => $plan([Roles::REVIEW_ACTION_PLANS]),
            'args'                => $id + [
                'decision'      => ['type' => 'string', 'required' => true, 'enum' => PlanWorkflow::DECISIONS],
                'title'         => ['type' => 'string', 'maxLength' => 200, 'sanitize_callback' => 'sanitize_text_field'],
                'description'   => ['type' => 'string', 'maxLength' => 2000, 'sanitize_callback' => 'sanitize_textarea_field'],
                'indicator'     => ['type' => 'string', 'maxLength' => 500, 'sanitize_callback' => 'sanitize_text_field'],
                'priority'      => ['type' => 'string', 'enum' => PlanWorkflow::PRIORITIES],
                'deadline_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730],
                'note'          => ['type' => 'string', 'maxLength' => 1000, 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ]);

        register_rest_route($ns, '/action-plans/(?P<id>\d+)/complete-review', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => fn (\WP_REST_Request $r) => Permissions::run(function () use ($r) {
                $this->plans->completeReview((int) $r['id'], get_current_user_id());
                return new \WP_REST_Response($this->plans->view((int) $r['id']));
            }),
            'permission_callback' => $plan([Roles::REVIEW_ACTION_PLANS]),
            'args'                => $id,
        ]);

        register_rest_route($ns, '/action-plans/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => fn (\WP_REST_Request $r) => Permissions::run(function () use ($r) {
                $this->plans->approve((int) $r['id'], get_current_user_id());
                return new \WP_REST_Response($this->plans->view((int) $r['id']));
            }),
            'permission_callback' => $plan([Roles::APPROVE_ACTION_PLANS]),
            'args'                => $id,
        ]);

        register_rest_route($ns, '/action-plans/(?P<id>\d+)/reject', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => fn (\WP_REST_Request $r) => Permissions::run(function () use ($r) {
                $this->plans->reject((int) $r['id'], get_current_user_id(), (string) $r['reason']);
                return new \WP_REST_Response($this->plans->view((int) $r['id']));
            }),
            'permission_callback' => $plan([Roles::APPROVE_ACTION_PLANS]),
            'args'                => $id + ['reason' => ['type' => 'string', 'required' => true, 'maxLength' => 1000, 'sanitize_callback' => 'sanitize_textarea_field']],
        ]);
    }
}
