<?php

declare(strict_types=1);

namespace EquiGov\Core\Cli;

use EquiGov\Core\Auth\InstitutionScope;
use EquiGov\Core\Diagnosis\QuestionnaireImporter;
use EquiGov\Core\Repositories\InstitutionRepository;

/**
 * Cria duas instituições e um usuário por papel, para demonstrar RBAC e isolamento.
 *
 * ## EXAMPLES
 *     wp equigov seed
 */
final class SeedCommand
{
    private const PASSWORD = 'demo-equigov-2026';

    public function __construct(
        private readonly InstitutionRepository $institutions,
        private readonly QuestionnaireImporter $importer,
    ) {
    }

    /** @param string[] $args @param array<string,string> $assoc */
    public function __invoke(array $args, array $assoc): void
    {
        $a = $this->institution('Prefeitura de Exemplo A', 'municipal');
        $b = $this->institution('Secretaria Estadual de Exemplo B', 'estadual');

        $this->user('gestora.a', 'eg_manager', $a);
        $this->user('revisor.a', 'eg_reviewer', $a);
        $this->user('leitor.a', 'eg_reader', $a);
        $this->user('gestor.b', 'eg_manager', $b);

        foreach (glob(dirname(EQUIGOV_CORE_FILE) . '/data/questionnaires/*.json') ?: [] as $file) {
            $r = $this->importer->import((string) file_get_contents($file));
            \WP_CLI::log(sprintf('Questionário %s: %s (#%d)', basename($file), $r['action'], $r['id']));
        }

        \WP_CLI::success('Seed concluído. Senha dos usuários de demo: ' . self::PASSWORD);
    }

    private function institution(string $name, string $sphere): int
    {
        $existing = $this->institutions->findBySlug($name);
        if ($existing) {
            return $existing['id'];
        }
        $id = $this->institutions->create($name, $sphere);
        \WP_CLI::log("Instituição criada: {$name} (#{$id})");
        return $id;
    }

    private function user(string $login, string $role, int $institutionId): void
    {
        $user = get_user_by('login', $login);
        $id   = $user ? $user->ID : wp_insert_user([
            'user_login' => $login,
            'user_pass'  => self::PASSWORD,
            'user_email' => "{$login}@equigov.local",
            'role'       => $role,
        ]);
        if (is_wp_error($id)) {
            \WP_CLI::warning("{$login}: " . $id->get_error_message());
            return;
        }
        update_user_meta($id, InstitutionScope::USER_META, $institutionId);
        \WP_CLI::log("Usuário {$login} ({$role}) → instituição #{$institutionId}");
    }
}
