<?php

declare(strict_types=1);

namespace EquiGov\Core;

use EquiGov\Core\Api\DiagnosisController;
use EquiGov\Core\Api\HealthController;
use EquiGov\Core\Api\QuestionnaireController;
use EquiGov\Core\Audit\AuditLogger;
use EquiGov\Core\Auth\InstitutionScope;
use EquiGov\Core\Cli\SeedCommand;
use EquiGov\Core\Diagnosis\DiagnosisService;
use EquiGov\Core\Diagnosis\QuestionnaireImporter;
use EquiGov\Core\PostTypes\Registrar;
use EquiGov\Core\Repositories\InstitutionRepository;
use EquiGov\Core\Repositories\ResponseRepository;

/**
 * Ponto único de composição: cria os serviços e registra os hooks.
 * Nenhuma outra classe chama add_action/add_filter por conta própria.
 */
final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        global $wpdb;

        $institutions = new InstitutionRepository($wpdb);
        $responses    = new ResponseRepository($wpdb);
        $audit        = new AuditLogger($wpdb);
        $scope        = new InstitutionScope();
        $diagnoses    = new DiagnosisService($responses, $audit);

        Installer::maybeUpgrade();

        add_action('init', [Registrar::class, 'register']);
        add_filter('map_meta_cap', [$scope, 'filterMetaCaps'], 10, 4);
        add_action('transition_post_status', [$audit, 'onStatusTransition'], 10, 3);

        add_action('rest_api_init', static function () use ($wpdb, $diagnoses): void {
            (new HealthController($wpdb))->register();
            (new QuestionnaireController())->register();
            (new DiagnosisController($diagnoses))->register();
        });

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('equigov seed', new SeedCommand($institutions, new QuestionnaireImporter()));
        }
    }
}
