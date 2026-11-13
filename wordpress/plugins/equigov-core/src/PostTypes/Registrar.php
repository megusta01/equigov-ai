<?php

declare(strict_types=1);

namespace EquiGov\Core\PostTypes;

use EquiGov\Core\Auth\InstitutionScope;
use EquiGov\Core\Auth\Roles;

/**
 * Tipos de conteúdo, taxonomia e metadados.
 * Conteúdo editorial (questionários, recursos) usa CPT; dados de alto volume
 * (respostas, auditoria) ficam em tabelas próprias — ver Installer.
 */
final class Registrar
{
    public const QUESTIONNAIRE = 'eg_questionnaire';
    public const DIAGNOSIS     = 'eg_diagnosis';
    public const ACTION_PLAN   = 'eg_action_plan';
    public const RESOURCE      = 'eg_resource';
    public const DIMENSION     = 'eg_dimension';

    /** Tipos cujos registros pertencem a uma instituição. */
    public const INSTITUTION_SCOPED = [self::DIAGNOSIS, self::ACTION_PLAN];

    public const DIAGNOSIS_STATUSES = ['draft', 'in_progress', 'submitted', 'scored'];
    public const RISK_LEVELS        = ['low', 'moderate', 'high', 'critical'];

    public static function register(): void
    {
        self::postType(self::QUESTIONNAIRE, 'Questionários', 'Questionário', Roles::MANAGE_QUESTIONNAIRES, Roles::VIEW_DIAGNOSES, 'dashicons-forms', ['title', 'editor', 'revisions']);
        self::postType(self::DIAGNOSIS, 'Diagnósticos', 'Diagnóstico', Roles::MANAGE_DIAGNOSES, Roles::VIEW_DIAGNOSES, 'dashicons-chart-bar', ['title']);
        self::postType(self::ACTION_PLAN, 'Planos de ação', 'Plano de ação', Roles::APPROVE_ACTION_PLANS, Roles::VIEW_DIAGNOSES, 'dashicons-yes-alt', ['title', 'editor', 'revisions']);
        self::postType(self::RESOURCE, 'Biblioteca', 'Recurso', Roles::MANAGE_LIBRARY, 'read', 'dashicons-book', ['title', 'editor', 'excerpt', 'thumbnail']);

        register_taxonomy(self::DIMENSION, [self::QUESTIONNAIRE, self::RESOURCE, self::ACTION_PLAN], [
            'labels'            => ['name' => 'Dimensões', 'singular_name' => 'Dimensão'],
            'hierarchical'      => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'capabilities'      => [
                'manage_terms' => Roles::MANAGE_QUESTIONNAIRES,
                'edit_terms'   => Roles::MANAGE_QUESTIONNAIRES,
                'delete_terms' => Roles::MANAGE_QUESTIONNAIRES,
                'assign_terms' => Roles::MANAGE_QUESTIONNAIRES,
            ],
        ]);

        self::registerMeta();
    }

    /** @param string[] $supports */
    private static function postType(string $slug, string $plural, string $singular, string $manageCap, string $readCap, string $icon, array $supports): void
    {
        register_post_type($slug, [
            'labels'       => ['name' => $plural, 'singular_name' => $singular, 'add_new_item' => "Adicionar {$singular}"],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => $icon,
            'supports'     => $supports,
            // Exposição pela REST fica nos endpoints próprios (Dia 2), que aplicam o escopo institucional.
            'show_in_rest' => false,
            'map_meta_cap' => true,
            'capabilities' => self::capabilities($manageCap, $readCap),
        ]);
    }

    /**
     * Mapeia as capacidades primitivas do CPT para as capacidades EquiGov.
     * @return array<string, string>
     */
    public static function capabilities(string $manageCap, string $readCap): array
    {
        $primitive = [
            'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts',
            'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_private_posts',
            'delete_published_posts', 'create_posts',
        ];
        $caps = array_fill_keys($primitive, $manageCap);
        $caps['read']               = $readCap;
        $caps['read_private_posts'] = $readCap;

        return $caps;
    }

    private static function registerMeta(): void
    {
        $canManage = static fn (): bool => current_user_can(Roles::MANAGE_DIAGNOSES);

        $meta = [
            self::DIAGNOSIS => [
                InstitutionScope::POST_META => ['type' => 'integer', 'sanitize' => 'absint'],
                '_eg_questionnaire_id'      => ['type' => 'integer', 'sanitize' => 'absint'],
                '_eg_questionnaire_version' => ['type' => 'integer', 'sanitize' => 'absint'],
                '_eg_status'                => ['type' => 'string',  'sanitize' => self::enum(self::DIAGNOSIS_STATUSES, 'draft')],
                '_eg_score'                 => ['type' => 'number',  'sanitize' => static fn ($v): float => max(0.0, min(100.0, (float) $v))],
                '_eg_risk_level'            => ['type' => 'string',  'sanitize' => self::enum(self::RISK_LEVELS, 'low')],
            ],
            self::ACTION_PLAN => [
                InstitutionScope::POST_META => ['type' => 'integer', 'sanitize' => 'absint'],
                '_eg_diagnosis_id'          => ['type' => 'integer', 'sanitize' => 'absint'],
                '_eg_approved_by'           => ['type' => 'integer', 'sanitize' => 'absint'],
            ],
            self::QUESTIONNAIRE => [
                '_eg_version'               => ['type' => 'integer', 'sanitize' => 'absint'],
                '_eg_schema'                => ['type' => 'string',  'sanitize' => [self::class, 'sanitizeJson']],
            ],
        ];

        foreach ($meta as $postType => $keys) {
            foreach ($keys as $key => $def) {
                register_post_meta($postType, $key, [
                    'type'              => $def['type'],
                    'single'            => true,
                    'sanitize_callback' => $def['sanitize'],
                    'auth_callback'     => $canManage,
                    'show_in_rest'      => false,
                ]);
            }
        }
    }

    /** @param string[] $allowed */
    private static function enum(array $allowed, string $default): \Closure
    {
        return static fn ($v): string => in_array($v, $allowed, true) ? $v : $default;
    }

    public static function sanitizeJson(mixed $value): string
    {
        if (!is_string($value)) {
            return '{}';
        }
        try {
            json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            return $value;
        } catch (\JsonException) {
            return '{}';
        }
    }
}
