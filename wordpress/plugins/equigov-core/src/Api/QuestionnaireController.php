<?php

declare(strict_types=1);

namespace EquiGov\Core\Api;

use EquiGov\Core\Auth\Roles;
use EquiGov\Core\Diagnosis\QuestionnaireSchema;
use EquiGov\Core\Diagnosis\SchemaException;
use EquiGov\Core\PostTypes\Registrar;

/**
 *   GET /questionnaires        questionários publicados
 *   GET /questionnaires/{id}   schema público (sem a régua de risco)
 */
final class QuestionnaireController
{
    public function register(): void
    {
        $perm = static fn () => current_user_can(Roles::VIEW_DIAGNOSES)
            ?: new \WP_Error('rest_forbidden', 'Sem permissão.', ['status' => is_user_logged_in() ? 403 : 401]);

        register_rest_route(HealthController::NAMESPACE, '/questionnaires', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => $perm,
        ]);

        register_rest_route(HealthController::NAMESPACE, '/questionnaires/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => $perm,
            'args'                => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);
    }

    public function index(): \WP_REST_Response
    {
        $posts = get_posts([
            'post_type'   => Registrar::QUESTIONNAIRE,
            'post_status' => 'publish',
            'numberposts' => 50,
        ]);

        return new \WP_REST_Response(array_map(static fn (\WP_Post $p) => [
            'id'      => $p->ID,
            'title'   => $p->post_title,
            'version' => (int) get_post_meta($p->ID, '_eg_version', true),
        ], $posts));
    }

    public function show(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $post = get_post((int) $r['id']);
        if (!$post || $post->post_type !== Registrar::QUESTIONNAIRE || $post->post_status !== 'publish') {
            return new \WP_Error('eg_not_found', 'Questionário não encontrado.', ['status' => 404]);
        }
        try {
            $schema = QuestionnaireSchema::fromJson((string) get_post_meta($post->ID, '_eg_schema', true));
        } catch (SchemaException) {
            return new \WP_Error('eg_schema_invalid', 'Questionário corrompido.', ['status' => 500]);
        }
        return new \WP_REST_Response(['id' => $post->ID] + $schema->toPublicArray());
    }
}
