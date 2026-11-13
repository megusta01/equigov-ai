<?php

declare(strict_types=1);

namespace EquiGov\Core\Auth;

use EquiGov\Core\PostTypes\Registrar;

/**
 * Aplica InstitutionPolicy no sistema de capacidades do WordPress.
 * Assim, até o admin nativo e o WP-CLI respeitam o isolamento.
 */
final class InstitutionScope
{
    public const USER_META = 'eg_institution_id';
    public const POST_META = '_eg_institution_id';

    private const GUARDED_META_CAPS = ['read_post', 'edit_post', 'delete_post', 'publish_post'];

    public static function institutionOf(int $userId): int
    {
        return (int) get_user_meta($userId, self::USER_META, true);
    }

    /**
     * @param string[] $caps
     * @param mixed[]  $args
     * @return string[]
     */
    public function filterMetaCaps(array $caps, string $cap, int $userId, array $args): array
    {
        if (!in_array($cap, self::GUARDED_META_CAPS, true) || empty($args[0])) {
            return $caps;
        }

        $post = get_post((int) $args[0]);
        if (!$post || !in_array($post->post_type, Registrar::INSTITUTION_SCOPED, true)) {
            return $caps;
        }

        // Rascunho recém-criado pelo próprio usuário ainda sem meta: deixa o fluxo normal decidir.
        $resourceInstitution = (int) get_post_meta($post->ID, self::POST_META, true);
        if ($resourceInstitution === 0 && $post->post_status === 'auto-draft') {
            return $caps;
        }

        $allowed = InstitutionPolicy::canAccess(
            self::institutionOf($userId),
            $resourceInstitution,
            user_can($userId, Roles::MANAGE_INSTITUTIONS)
        );

        return $allowed ? $caps : ['do_not_allow'];
    }
}
