<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

use EquiGov\Core\Audit\AuditLogger;
use EquiGov\Core\Auth\InstitutionScope;
use EquiGov\Core\PostTypes\Registrar;
use EquiGov\Core\Repositories\ResponseRepository;
use EquiGov\Core\Support\DomainError;

/**
 * Casos de uso do diagnóstico. Autorização de "quem" fica no controller (capacidades);
 * aqui ficam as regras de "quando" (estado) e "o quê" (validação).
 *
 * Ciclo de vida: draft → in_progress → scored
 */
final class DiagnosisService
{
    public function __construct(
        private readonly ResponseRepository $responses,
        private readonly AuditLogger $audit,
    ) {
    }

    public function create(int $userId, int $questionnaireId, string $title = ''): int
    {
        $institution = InstitutionScope::institutionOf($userId);
        if ($institution <= 0) {
            throw new DomainError('eg_no_institution', 'Usuário sem instituição vinculada.', 403);
        }

        $questionnaire = get_post($questionnaireId);
        if (!$questionnaire || $questionnaire->post_type !== Registrar::QUESTIONNAIRE || $questionnaire->post_status !== 'publish') {
            throw DomainError::notFound('Questionário');
        }

        $schemaJson = (string) get_post_meta($questionnaireId, '_eg_schema', true);
        $schema     = QuestionnaireSchema::fromJson($schemaJson); // falha alto se o questionário estiver corrompido

        $id = wp_insert_post([
            'post_type'   => Registrar::DIAGNOSIS,
            'post_status' => 'private',
            'post_author' => $userId,
            'post_title'  => $title !== '' ? sanitize_text_field($title) : $schema->title . ' — ' . wp_date('d/m/Y'),
            'meta_input'  => [
                InstitutionScope::POST_META => $institution,
                '_eg_questionnaire_id'      => $questionnaireId,
                '_eg_questionnaire_version' => $schema->version,
                // Snapshot: editar o questionário depois não altera diagnósticos já abertos.
                // wp_slash porque update_post_meta remove barras e corromperia o JSON.
                '_eg_schema_snapshot'       => wp_slash($schemaJson),
                '_eg_status'                => 'draft',
            ],
        ], true);

        if (is_wp_error($id)) {
            throw new DomainError('eg_db_error', $id->get_error_message(), 500);
        }

        $this->audit->log('diagnosis_created', Registrar::DIAGNOSIS, $id, $institution, [
            'questionnaire_id' => $questionnaireId, 'version' => $schema->version,
        ]);
        return $id;
    }

    /** @param array<string,mixed> $answers @return array<string,mixed> */
    public function saveAnswers(int $diagnosisId, int $userId, array $answers): array
    {
        $post   = $this->load($diagnosisId);
        $status = $this->status($post);
        if ($status === 'scored') {
            throw new DomainError('eg_locked', 'Diagnóstico já concluído não pode ser alterado.', 409);
        }

        $schema = $this->schema($post);
        $result = AnswerValidator::validatePartial($schema, $answers);
        if ($result['errors'] !== []) {
            throw new DomainError('eg_invalid_answers', 'Há respostas inválidas.', 422, ['fields' => $result['errors']]);
        }

        $institution = $this->institution($post);
        $this->responses->upsertMany($diagnosisId, $institution, $userId, $result['answers']);
        update_post_meta($diagnosisId, '_eg_status', 'in_progress');

        $this->audit->log('answers_saved', Registrar::DIAGNOSIS, $diagnosisId, $institution, [
            'keys' => array_keys($result['answers']),
        ]);

        return $this->progress($schema, $this->responses->forDiagnosis($diagnosisId));
    }

    /** @return array<string,mixed> */
    public function submit(int $diagnosisId): array
    {
        $post = $this->load($diagnosisId);
        if ($this->status($post) === 'scored') {
            throw new DomainError('eg_locked', 'Diagnóstico já concluído.', 409);
        }

        $schema   = $this->schema($post);
        $complete = AnswerValidator::validateComplete($schema, $this->responses->forDiagnosis($diagnosisId));

        if ($complete['missing'] !== []) {
            throw new DomainError('eg_incomplete', 'Responda todas as perguntas obrigatórias.', 422, ['missing' => $complete['missing']]);
        }

        // Respostas de perguntas que ficaram ocultas não podem pesar na nota nem ficar guardadas.
        $this->responses->deleteKeys($diagnosisId, $complete['hidden']);

        $result = ScoringEngine::score($schema, $complete['visible'])->toArray();
        $result['scored_at'] = gmdate('c');

        update_post_meta($diagnosisId, '_eg_result', wp_slash(wp_json_encode($result, JSON_UNESCAPED_UNICODE)));
        update_post_meta($diagnosisId, '_eg_score', $result['score']);
        update_post_meta($diagnosisId, '_eg_risk_level', $result['risk_level']);
        update_post_meta($diagnosisId, '_eg_status', 'scored');

        $this->audit->log('diagnosis_scored', Registrar::DIAGNOSIS, $diagnosisId, $this->institution($post), [
            'score' => $result['score'], 'risk_level' => $result['risk_level'],
            'rule_version' => $result['rule_version'], 'discarded' => $complete['hidden'],
        ]);

        return $result;
    }

    /** @return array<string,mixed> */
    public function view(int $diagnosisId, bool $withAnswers = true): array
    {
        $post   = $this->load($diagnosisId);
        $schema = $this->schema($post);
        $result = json_decode((string) get_post_meta($post->ID, '_eg_result', true), true);

        $data = [
            'id'                    => $post->ID,
            'title'                 => $post->post_title,
            'institution_id'        => $this->institution($post),
            'questionnaire_id'      => (int) get_post_meta($post->ID, '_eg_questionnaire_id', true),
            'questionnaire_version' => $schema->version,
            'status'                => $this->status($post),
            'created_at'            => mysql_to_rfc3339($post->post_date_gmt),
            'score'                 => $result['score'] ?? null,
            'risk_level'            => $result['risk_level'] ?? null,
        ];

        if ($withAnswers) {
            $answers          = $this->responses->forDiagnosis($post->ID);
            $data['answers']  = $answers;
            $data['progress'] = $this->progress($schema, $answers);
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function report(int $diagnosisId): array
    {
        $post = $this->load($diagnosisId);
        if ($this->status($post) !== 'scored') {
            throw new DomainError('eg_not_scored', 'O relatório só existe após o envio do diagnóstico.', 409);
        }
        return ['diagnosis' => $this->view($diagnosisId, false)]
            + json_decode((string) get_post_meta($post->ID, '_eg_result', true), true);
    }

    public function schemaFor(int $diagnosisId): QuestionnaireSchema
    {
        return $this->schema($this->load($diagnosisId));
    }

    /** Perguntas visíveis agora, quantas respondidas, quais faltam. */
    private function progress(QuestionnaireSchema $schema, array $answers): array
    {
        $c        = AnswerValidator::validateComplete($schema, $answers);
        $answered = count($c['visible']);
        $total    = count($c['visible_keys']);

        return [
            'answered'          => $answered,
            'visible_questions' => $c['visible_keys'],
            'required_missing'  => $c['missing'],
            'percent'           => $total > 0 ? (int) round($answered / $total * 100) : 0,
            'can_submit'        => $c['missing'] === [],
        ];
    }

    private function load(int $id): \WP_Post
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== Registrar::DIAGNOSIS) {
            throw DomainError::notFound('Diagnóstico');
        }
        return $post;
    }

    private function schema(\WP_Post $post): QuestionnaireSchema
    {
        return QuestionnaireSchema::fromJson((string) get_post_meta($post->ID, '_eg_schema_snapshot', true));
    }

    private function status(\WP_Post $post): string
    {
        return (string) get_post_meta($post->ID, '_eg_status', true) ?: 'draft';
    }

    private function institution(\WP_Post $post): int
    {
        return (int) get_post_meta($post->ID, InstitutionScope::POST_META, true);
    }
}
