<?php

declare(strict_types=1);

namespace EquiGov\Core\Api;

use EquiGov\Core\Support\DomainError;

/**
 * Regras de permissão compartilhadas pelos controllers.
 *  - não autenticado → 401
 *  - registro de outra instituição ou inexistente → 404 (não confirma existência)
 *  - pode ver, mas não pode agir → 403
 */
final class Permissions
{
    public static function cap(string $cap): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_not_logged_in', 'Autenticação necessária.', ['status' => 401]);
        }
        return current_user_can($cap) ?: new \WP_Error('rest_forbidden', 'Sem permissão.', ['status' => 403]);
    }

    /**
     * @param string        $postType tipo esperado
     * @param string|null   $metaCap  meta capacidade extra sobre o post (ex.: edit_post)
     * @param list<string>  $caps     capacidades primitivas exigidas
     */
    public static function post(int $postId, string $postType, ?string $metaCap = null, array $caps = [], string $label = 'Registro'): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_not_logged_in', 'Autenticação necessária.', ['status' => 401]);
        }
        $post = get_post($postId);
        if (!$post || $post->post_type !== $postType || !current_user_can('read_post', $postId)) {
            return DomainError::notFound($label)->toWpError();
        }
        if ($metaCap !== null && !current_user_can($metaCap, $postId)) {
            return new \WP_Error('rest_forbidden', 'Sem permissão.', ['status' => 403]);
        }
        foreach ($caps as $cap) {
            if (!current_user_can($cap)) {
                return new \WP_Error('rest_forbidden', 'Sem permissão.', ['status' => 403]);
            }
        }
        return true;
    }

    /** Converte DomainError em WP_Error para respostas REST. */
    public static function run(callable $fn): \WP_REST_Response|\WP_Error
    {
        try {
            return $fn();
        } catch (DomainError $e) {
            return $e->toWpError();
        }
    }
}
