<?php

declare(strict_types=1);

namespace EquiGov\Core\Api;

use EquiGov\Core\Installer;

/**
 * GET /wp-json/equigov/v1/health — usado por monitoramento e pelo pipeline de deploy.
 * Público de propósito, mas não expõe nada sensível.
 */
final class HealthController
{
    public const NAMESPACE = 'equigov/v1';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(): \WP_REST_Response
    {
        $dbOk     = (int) $this->db->get_var('SELECT 1') === 1;
        $schemaOk = get_option('equigov_db_version') === Installer::DB_VERSION;
        $healthy  = $dbOk && $schemaOk;

        return new \WP_REST_Response([
            'status'  => $healthy ? 'ok' : 'degraded',
            'version' => EQUIGOV_CORE_VERSION,
            'checks'  => ['database' => $dbOk, 'schema' => $schemaOk],
            'time'    => gmdate('c'),
        ], $healthy ? 200 : 503);
    }
}
