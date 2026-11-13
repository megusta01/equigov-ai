<?php

declare(strict_types=1);

namespace EquiGov\Core\Repositories;

/**
 * Acesso à tabela eg_institutions. Toda consulta com entrada externa passa por prepare().
 */
final class InstitutionRepository
{
    private const SPHERES = ['municipal', 'estadual', 'federal'];

    public function __construct(private readonly \wpdb $db)
    {
    }

    private function table(): string
    {
        return $this->db->prefix . 'eg_institutions';
    }

    /** @return array{id:int,name:string,slug:string,sphere:string,created_at:string}|null */
    public function find(int $id): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table()} WHERE slug = %s", sanitize_title($slug)),
            ARRAY_A
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $sphere = 'municipal'): int
    {
        $name = sanitize_text_field($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Nome da instituição é obrigatório.');
        }
        if (!in_array($sphere, self::SPHERES, true)) {
            throw new \InvalidArgumentException("Esfera inválida: {$sphere}");
        }

        $ok = $this->db->insert(
            $this->table(),
            ['name' => $name, 'slug' => sanitize_title($name), 'sphere' => $sphere, 'created_at' => current_time('mysql', true)],
            ['%s', '%s', '%s', '%s']
        );
        if ($ok === false) {
            throw new \RuntimeException('Falha ao criar instituição: ' . $this->db->last_error);
        }
        return (int) $this->db->insert_id;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'slug'       => (string) $row['slug'],
            'sphere'     => (string) $row['sphere'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
