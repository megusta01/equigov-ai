<?php

declare(strict_types=1);

namespace EquiGov\Core\Api;

use EquiGov\Core\Auth\InstitutionScope;
use EquiGov\Core\Auth\Roles;
use EquiGov\Core\Diagnosis\DiagnosisService;
use EquiGov\Core\PostTypes\Registrar;
use EquiGov\Core\Support\DomainError;

/**
 * Endpoints de diagnóstico (namespace equigov/v1):
 *
 *   GET    /diagnoses                  lista da instituição do usuário
 *   POST   /diagnoses                  cria a partir de um questionário
 *   GET    /diagnoses/{id}             detalhes + respostas + progresso
 *   PATCH  /diagnoses/{id}/answers     salva respostas parciais
 *   POST   /diagnoses/{id}/submit      valida, pontua e trava
 *   GET    /diagnoses/{id}/report      relatório com achados explicáveis
 *
 * Autenticação: cookie + nonce (X-WP-Nonce) no front; Application Passwords para clientes externos.
 */
final class DiagnosisController
{
    public function __construct(private readonly DiagnosisService $service)
    {
    }

    public function register(): void
    {
        $ns = HealthController::NAMESPACE;
        $id = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];

        register_rest_route($ns, '/diagnoses', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => fn () => $this->requireCap(Roles::VIEW_DIAGNOSES),
                'args'                => [
                    'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                    'status'   => ['type' => 'string', 'enum' => Registrar::DIAGNOSIS_STATUSES],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => fn () => $this->requireCap(Roles::ANSWER_DIAGNOSES),
                'args'                => [
                    'questionnaire_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                    'title'            => ['type' => 'string', 'maxLength' => 200, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ],
        ]);

        register_rest_route($ns, '/diagnoses/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => fn (\WP_REST_Request $r) => $this->requirePostCap('read_post', (int) $r['id']),
            'args'                => $id,
        ]);

        register_rest_route($ns, '/diagnoses/(?P<id>\d+)/answers', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'answers'],
            'permission_callback' => fn (\WP_REST_Request $r) => $this->requirePostCap('edit_post', (int) $r['id'], Roles::ANSWER_DIAGNOSES),
            'args'                => $id + ['answers' => ['type' => 'object', 'required' => true, 'minProperties' => 1, 'maxProperties' => 100]],
        ]);

        register_rest_route($ns, '/diagnoses/(?P<id>\d+)/submit', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'submit'],
            'permission_callback' => fn (\WP_REST_Request $r) => $this->requirePostCap('edit_post', (int) $r['id'], Roles::ANSWER_DIAGNOSES),
            'args'                => $id,
        ]);

        register_rest_route($ns, '/diagnoses/(?P<id>\d+)/report', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'report'],
            'permission_callback' => fn (\WP_REST_Request $r) => $this->requirePostCap('read_post', (int) $r['id']),
            'args'                => $id,
        ]);
    }

    public function index(\WP_REST_Request $r): \WP_REST_Response
    {
        $user = get_current_user_id();
        $meta = [];

        // Admin da plataforma vê tudo; os demais, só a própria instituição (filtro no banco, não depois).
        if (!current_user_can(Roles::MANAGE_INSTITUTIONS)) {
            $meta[] = ['key' => InstitutionScope::POST_META, 'value' => InstitutionScope::institutionOf($user), 'type' => 'NUMERIC'];
        }
        if ($r['status']) {
            $meta[] = ['key' => '_eg_status', 'value' => $r['status']];
        }

        $q = new \WP_Query([
            'post_type'      => Registrar::DIAGNOSIS,
            'post_status'    => ['private', 'publish', 'draft'],
            'posts_per_page' => $r['per_page'],
            'paged'          => $r['page'],
            'meta_query'     => $meta,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);

        $items    = array_map(fn (int $id) => $this->service->view($id, false), $q->posts);
        $response = new \WP_REST_Response($items);
        $response->header('X-WP-Total', (string) $q->found_posts);
        $response->header('X-WP-TotalPages', (string) $q->max_num_pages);
        return $response;
    }

    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->run(function () use ($r) {
            $id = $this->service->create(get_current_user_id(), (int) $r['questionnaire_id'], (string) ($r['title'] ?? ''));
            $response = new \WP_REST_Response($this->service->view($id), 201);
            $response->header('Location', rest_url(HealthController::NAMESPACE . "/diagnoses/{$id}"));
            return $response;
        });
    }

    public function show(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->run(fn () => new \WP_REST_Response(
            $this->service->view((int) $r['id']) + ['questionnaire' => $this->service->schemaFor((int) $r['id'])->toPublicArray()]
        ));
    }

    public function answers(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->run(fn () => new \WP_REST_Response(
            ['progress' => $this->service->saveAnswers((int) $r['id'], get_current_user_id(), (array) $r['answers'])]
        ));
    }

    public function submit(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->run(fn () => new \WP_REST_Response($this->service->submit((int) $r['id'])));
    }

    public function report(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->run(fn () => new \WP_REST_Response($this->service->report((int) $r['id'])));
    }

    /** Converte erros de domínio em respostas REST padronizadas. */
    private function run(callable $fn): \WP_REST_Response|\WP_Error
    {
        try {
            return $fn();
        } catch (DomainError $e) {
            return $e->toWpError();
        } catch (\EquiGov\Core\Diagnosis\SchemaException $e) {
            return new \WP_Error('eg_schema_invalid', 'Questionário corrompido.', ['status' => 500]);
        }
    }

    private function requireCap(string $cap): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_not_logged_in', 'Autenticação necessária.', ['status' => 401]);
        }
        return current_user_can($cap)
            ? true
            : new \WP_Error('rest_forbidden', 'Sem permissão.', ['status' => 403]);
    }

    /**
     * Para recursos de outra instituição respondemos 404, não 403:
     * não confirmamos nem a existência do registro (evita enumeração de IDs).
     */
    private function requirePostCap(string $metaCap, int $postId, ?string $extraCap = null): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_not_logged_in', 'Autenticação necessária.', ['status' => 401]);
        }
        $post = get_post($postId);
        // Não pode nem ler (outra instituição ou inexistente) → 404.
        if (!$post || $post->post_type !== Registrar::DIAGNOSIS || !current_user_can('read_post', $postId)) {
            return DomainError::notFound('Diagnóstico')->toWpError();
        }
        // Pode ler mas não pode fazer esta ação (ex.: leitor tentando responder) → 403.
        if (!current_user_can($metaCap, $postId) || ($extraCap !== null && !current_user_can($extraCap))) {
            return new \WP_Error('rest_forbidden', 'Sem permissão.', ['status' => 403]);
        }
        return true;
    }
}
