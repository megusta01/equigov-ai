<?php

declare(strict_types=1);

namespace EquiGov\Core\Audit;

use EquiGov\Core\Auth\InstitutionScope;
use EquiGov\Core\PostTypes\Registrar;

/**
 * Trilha de auditoria append-only. IP é guardado como hash com salt (LGPD: minimização).
 */
final class AuditLogger
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $action, string $objectType, int $objectId = 0, ?int $institutionId = null, array $context = []): void
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        $this->db->insert(
            $this->db->prefix . 'eg_audit_log',
            [
                'institution_id' => $institutionId,
                'user_id'        => get_current_user_id(),
                'action'         => substr($action, 0, 64),
                'object_type'    => substr($objectType, 0, 32),
                'object_id'      => $objectId,
                'context'        => wp_json_encode($context),
                'ip_hash'        => $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : '',
                'created_at'     => current_time('mysql', true),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    public function onStatusTransition(string $new, string $old, \WP_Post $post): void
    {
        if ($new === $old || !in_array($post->post_type, Registrar::INSTITUTION_SCOPED, true)) {
            return;
        }
        $institution = (int) get_post_meta($post->ID, InstitutionScope::POST_META, true);
        $this->log('status_changed', $post->post_type, $post->ID, $institution ?: null, ['from' => $old, 'to' => $new]);
    }
}
