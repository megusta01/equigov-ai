<?php
/**
 * Teste de integração da API contra o WordPress e o banco reais.
 * Roda dentro do container:  wp eval-file wp-content/plugins/equigov-core/tests/smoke/api-smoke.php
 * (atalho: .\tasks.ps1 smoke  |  make smoke)
 *
 * Prova, entre outras coisas, que o gestor da instituição B não enxerga dados da A.
 * Limpa o que criou ao final. Sai com código 1 se algo falhar.
 *
 * Sem declare(strict_types): o eval-file do WP-CLI executa via eval(), onde ele é proibido.
 */

use EquiGov\Core\Api\HealthController;

$ns       = '/' . HealthController::NAMESPACE;
$failures = 0;
$created  = [];

$as = static function (?string $login): void {
    $user = $login ? get_user_by('login', $login) : null;
    if ($login && !$user) {
        WP_CLI::error("Usuário {$login} não existe. Rode: wp equigov seed");
    }
    wp_set_current_user($user ? $user->ID : 0);
};

$call = static function (string $method, string $route, array $params = []) use ($ns): WP_REST_Response {
    $req = new WP_REST_Request($method, $ns . $route);
    foreach ($params as $k => $v) {
        $req->set_param($k, $v);
    }
    return rest_ensure_response(rest_do_request($req));
};

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    WP_CLI::log(($ok ? '  ✔ ' : '  ✘ ') . $label . ($ok || $detail === '' ? '' : " — {$detail}"));
    $failures += $ok ? 0 : 1;
};

$expect = static function (string $label, WP_REST_Response $res, int $status) use ($check): array {
    $data = (array) $res->get_data();
    $check("{$label} → {$status}", $res->get_status() === $status, "recebido {$res->get_status()}: " . wp_json_encode($data));
    return $data;
};

$questionnaire = get_page_by_path('acesso_servicos', OBJECT, 'eg_questionnaire');
if (!$questionnaire) {
    WP_CLI::error('Questionário de exemplo não encontrado. Rode: wp equigov seed');
}
$qid = $questionnaire->ID;

WP_CLI::log('Autenticação e papéis');
$as(null);
$expect('health é público', $call('GET', '/health'), 200);
$expect('anônimo não lista diagnósticos', $call('GET', '/diagnoses'), 401);
$as('leitor.a');
$expect('leitor não cria diagnóstico', $call('POST', '/diagnoses', ['questionnaire_id' => $qid]), 403);

WP_CLI::log('Criação e validação');
$as('gestora.a');
$data = $expect('gestora A cria diagnóstico', $call('POST', '/diagnoses', ['questionnaire_id' => $qid]), 201);
$diag = (int) ($data['id'] ?? 0);
$created[] = $diag;
$expect('questionário inexistente', $call('POST', '/diagnoses', ['questionnaire_id' => 999999]), 404);

$data = $expect('resposta inválida é rejeitada', $call('PATCH', "/diagnoses/{$diag}/answers", [
    'answers' => ['canais' => 'teletransporte', 'nao_existe' => 'x'],
]), 422);
$check('erro aponta os campos', isset($data['data']['fields']['canais'], $data['data']['fields']['nao_existe']));

$expect('salva respostas parciais', $call('PATCH', "/diagnoses/{$diag}/answers", [
    'answers' => ['canais' => 'so_digital', 'libras' => 'nao_ha'],
]), 200);
$data = $expect('envio incompleto é bloqueado', $call('POST', "/diagnoses/{$diag}/submit"), 422);
$check('lista as obrigatórias faltando', in_array('digital_acessivel', $data['data']['missing'] ?? [], true));

WP_CLI::log('Isolamento entre instituições');
$as('gestor.b');
$expect('gestor B não lê diagnóstico de A', $call('GET', "/diagnoses/{$diag}"), 404);
$expect('gestor B não responde diagnóstico de A', $call('PATCH', "/diagnoses/{$diag}/answers", ['answers' => ['canais' => 'ambos']]), 404);
$expect('gestor B não envia diagnóstico de A', $call('POST', "/diagnoses/{$diag}/submit"), 404);
$list = $expect('gestor B lista os seus', $call('GET', '/diagnoses'), 200);
$check('lista de B não contém o diagnóstico de A', !in_array($diag, array_column($list, 'id'), true));

$as('leitor.a');
$expect('leitor A lê diagnóstico da própria instituição', $call('GET', "/diagnoses/{$diag}"), 200);
$expect('leitor A não responde (pode ler, não editar)', $call('PATCH', "/diagnoses/{$diag}/answers", ['answers' => ['canais' => 'ambos']]), 403);

WP_CLI::log('Pontuação e relatório');
$as('gestora.a');
$expect('completa as respostas', $call('PATCH', "/diagnoses/{$diag}/answers", ['answers' => [
    'digital_acessivel'     => 'nunca',
    'acessibilidade_fisica' => ['rampa'],  // ficará oculta: canal é só digital
    'linguagem_simples'     => 'parcial',
    'outros_idiomas'        => 'nao',
    'coleta_desagregada'    => ['genero'],
    'uso_dados'             => 'nunca',
    'capacitacao'           => 'nunca',
    'canal_denuncia'        => 'generico',
]]), 200);

$result = $expect('envio final', $call('POST', "/diagnoses/{$diag}/submit"), 200);
$check('nota esperada (81.0)', ($result['score'] ?? null) == 81.0, 'nota ' . wp_json_encode($result['score'] ?? null));
$check('nível crítico', ($result['risk_level'] ?? '') === 'critical');
$check('achado crítico vem primeiro (libras)', ($result['findings'][0]['question_key'] ?? '') === 'libras');
$check('achado traz referência legal', str_contains((string) ($result['findings'][0]['reference'] ?? ''), '10.436'));

global $wpdb;
$hidden = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}eg_responses WHERE diagnosis_id = %d AND question_key = 'acessibilidade_fisica'", $diag
));
$check('resposta de pergunta oculta foi descartada', $hidden === 0);

$expect('diagnóstico concluído fica travado', $call('PATCH', "/diagnoses/{$diag}/answers", ['answers' => ['canais' => 'ambos']]), 409);
$expect('relatório disponível', $call('GET', "/diagnoses/{$diag}/report"), 200);

$audit = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}eg_audit_log WHERE object_id = %d AND action IN ('diagnosis_created','answers_saved','diagnosis_scored')", $diag
));
$check('trilha de auditoria registrou o ciclo', $audit >= 3, "{$audit} eventos");

// Limpeza
foreach ($created as $id) {
    $wpdb->delete("{$wpdb->prefix}eg_responses", ['diagnosis_id' => $id], ['%d']);
    wp_delete_post($id, true);
}

if ($failures > 0) {
    WP_CLI::error("{$failures} verificação(ões) falharam.");
}
WP_CLI::success('Todas as verificações passaram.');
