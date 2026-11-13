<?php

declare(strict_types=1);

namespace EquiGov\Core\Repositories;

use EquiGov\Core\Support\DomainError;

/**
 * Respostas em tabela própria. Escrita em lote dentro de transação:
 * ou todas as respostas do envio são gravadas, ou nenhuma.
 */
final class ResponseRepository
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    private function table(): string
    {
        return $this->db->prefix . 'eg_responses';
    }

    /** @return array<string, string|list<string>> */
    public function forDiagnosis(int $diagnosisId): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare("SELECT question_key, answer_value FROM {$this->table()} WHERE diagnosis_id = %d", $diagnosisId),
            ARRAY_A
        );

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[$row['question_key']] = json_decode($row['answer_value'], true);
        }
        return $out;
    }

    /** @param array<string, string|list<string>> $answers já validadas */
    public function upsertMany(int $diagnosisId, int $institutionId, int $userId, array $answers): void
    {
        if ($answers === []) {
            return;
        }

        $now = current_time('mysql', true);
        $this->db->query('START TRANSACTION');

        try {
            foreach ($answers as $key => $value) {
                $sql = $this->db->prepare(
                    "INSERT INTO {$this->table()}
                        (diagnosis_id, institution_id, question_key, answer_value, answered_by, answered_at)
                     VALUES (%d, %d, %s, %s, %d, %s)
                     ON DUPLICATE KEY UPDATE
                        answer_value = VALUES(answer_value),
                        answered_by  = VALUES(answered_by),
                        answered_at  = VALUES(answered_at)",
                    $diagnosisId, $institutionId, $key, wp_json_encode($value, JSON_UNESCAPED_UNICODE), $userId, $now
                );
                if ($this->db->query($sql) === false) {
                    throw new DomainError('eg_db_error', 'Falha ao gravar respostas.', 500);
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    /** @param list<string> $keys */
    public function deleteKeys(int $diagnosisId, array $keys): int
    {
        if ($keys === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        return (int) $this->db->query($this->db->prepare(
            "DELETE FROM {$this->table()} WHERE diagnosis_id = %d AND question_key IN ({$placeholders})",
            $diagnosisId, ...$keys
        ));
    }
}
