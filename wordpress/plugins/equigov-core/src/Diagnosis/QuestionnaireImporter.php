<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

use EquiGov\Core\PostTypes\Registrar;

/**
 * Importa um questionário JSON como CPT publicado. Idempotente por (key, version):
 * mesma versão → não faz nada; versão nova → atualiza (diagnósticos antigos mantêm o snapshot).
 */
final class QuestionnaireImporter
{
    /** @return array{id:int, action:string} */
    public function import(string $json, int $authorId = 1): array
    {
        $schema   = QuestionnaireSchema::fromJson($json); // valida antes de tocar no banco
        $existing = get_page_by_path($schema->key, OBJECT, Registrar::QUESTIONNAIRE);

        if ($existing && (int) get_post_meta($existing->ID, '_eg_version', true) >= $schema->version) {
            return ['id' => $existing->ID, 'action' => 'unchanged'];
        }

        $postarr = [
            'post_type'   => Registrar::QUESTIONNAIRE,
            'post_status' => 'publish',
            'post_name'   => $schema->key,
            'post_title'  => $schema->title,
            'post_author' => $authorId,
            'meta_input'  => [
                '_eg_version' => $schema->version,
                '_eg_schema'  => wp_slash($json),
            ],
        ];
        if ($existing) {
            $postarr['ID'] = $existing->ID;
        }

        $id = wp_insert_post($postarr, true);
        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }
        wp_set_object_terms($id, array_values($schema->dimensions), Registrar::DIMENSION);

        return ['id' => $id, 'action' => $existing ? 'updated' : 'created'];
    }
}
