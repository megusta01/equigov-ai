<?php

declare(strict_types=1);

namespace EquiGov\Core\Auth;

/**
 * RBAC: papéis e capacidades próprias. O mapa é puro (testável);
 * install() apenas o aplica no WordPress.
 */
final class Roles
{
    public const VIEW_DIAGNOSES          = 'eg_view_diagnoses';
    public const ANSWER_DIAGNOSES        = 'eg_answer_diagnoses';
    public const MANAGE_DIAGNOSES        = 'eg_manage_diagnoses';
    public const REVIEW_ACTION_PLANS     = 'eg_review_action_plans';
    public const APPROVE_ACTION_PLANS    = 'eg_approve_action_plans';
    public const MANAGE_QUESTIONNAIRES   = 'eg_manage_questionnaires';
    public const MANAGE_LIBRARY          = 'eg_manage_library';
    public const MANAGE_INSTITUTIONS     = 'eg_manage_institutions';
    public const VIEW_AUDIT_LOG          = 'eg_view_audit_log';

    /** @return array<string, array{label: string, caps: list<string>}> */
    public static function map(): array
    {
        $reader   = ['read', self::VIEW_DIAGNOSES];
        $reviewer = [...$reader, self::REVIEW_ACTION_PLANS];
        $manager  = [...$reviewer, self::ANSWER_DIAGNOSES, self::MANAGE_DIAGNOSES, self::APPROVE_ACTION_PLANS, self::VIEW_AUDIT_LOG];

        return [
            'eg_reader'         => ['label' => 'Leitor(a) EquiGov', 'caps' => $reader],
            'eg_reviewer'       => ['label' => 'Revisor(a) EquiGov', 'caps' => $reviewer],
            'eg_manager'        => ['label' => 'Gestor(a) EquiGov', 'caps' => $manager],
            'eg_platform_admin' => ['label' => 'Administrador(a) da plataforma', 'caps' => self::all()],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'read',
            self::VIEW_DIAGNOSES, self::ANSWER_DIAGNOSES, self::MANAGE_DIAGNOSES,
            self::REVIEW_ACTION_PLANS, self::APPROVE_ACTION_PLANS,
            self::MANAGE_QUESTIONNAIRES, self::MANAGE_LIBRARY,
            self::MANAGE_INSTITUTIONS, self::VIEW_AUDIT_LOG,
        ];
    }

    public static function install(): void
    {
        foreach (self::map() as $slug => $role) {
            remove_role($slug); // recria para refletir mudanças no mapa
            add_role($slug, $role['label'], array_fill_keys($role['caps'], true));
        }

        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::all() as $cap) {
                $admin->add_cap($cap);
            }
        }
    }
}
