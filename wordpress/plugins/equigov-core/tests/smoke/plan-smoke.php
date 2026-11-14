<?php
/**
 * Integração do plano de ação: WordPress + banco + serviço de IA reais.
 *   wp eval-file wp-content/plugins/equigov-core/tests/smoke/plan-smoke.php
 *
 * Cobre: geração via IA, isolamento entre instituições, RBAC por etapa, decisões item a item,
 * preservação do texto original da IA, separação de funções (quem revisa não aprova),
 * trilha de auditoria. Limpa o que criou. Exit 1 se algo falhar.
 *
 * Sem declare(strict_types): eval-file executa via eval().
 */

use EquiGov\Core\Ai\AiClient;

$ns       = '/equigov/v1';
$failures = 0;
$created  = ['diagnoses' => [], 'plans' => []];

$as = static function (string $login): void {
    $u = get_user_by('login', $login) ?: WP_CLI::error("Usuário {$login} não existe. Rode: wp equigov seed");
    wp_set_current_user($u->ID);
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
    $check("{$label} → {$status}", $res->get_status() === $status, "recebido {$res->get_status()}: " . mb_substr((string) wp_json_encode($data), 0, 300));
    return $data;
};

// Pré-condições
$client = AiClient::fromConfig();
if (!$client->isConfigured()) {
    WP_CLI::error('Serviço de IA não configurado (EQUIGOV_AI_SERVICE_URL / EQUIGOV_AI_SERVICE_TOKEN).');
}
try {
    $health = $client->health();
} catch (Throwable $e) {
    WP_CLI::error('Serviço de IA inacessível: ' . $e->getMessage());
}
WP_CLI::log("Serviço de IA: {$health['status']} · {$health['llm']['provider']} / {$health['llm']['model']} · {$health['library']['chunks']} trechos");

$questionnaire = get_page_by_path('acesso_servicos', OBJECT, 'eg_questionnaire') ?: WP_CLI::error('Rode: wp equigov seed');

$newScoredDiagnosis = static function () use ($as, $call, $questionnaire, &$created): int {
    $as('gestora.a');
    $d = (array) $call('POST', '/diagnoses', ['questionnaire_id' => $questionnaire->ID])->get_data();
    $created['diagnoses'][] = (int) $d['id'];
    $call('PATCH', "/diagnoses/{$d['id']}/answers", ['answers' => [
        'canais' => 'so_digital', 'digital_acessivel' => 'nunca', 'libras' => 'nao_ha', 'linguagem_simples' => 'parcial',
        'outros_idiomas' => 'nao', 'coleta_desagregada' => ['genero'], 'uso_dados' => 'nunca', 'capacitacao' => 'nunca', 'canal_denuncia' => 'generico',
    ]]);
    $call('POST', "/diagnoses/{$d['id']}/submit");
    return (int) $d['id'];
};

/** Decide todos os itens pendentes; o primeiro é editado, os demais aceitos com justificativa. */
$decideAll = static function (array $plan, string $who) use ($as, $call): void {
    $as($who);
    foreach ($plan['items'] as $i => $item) {
        $body = $i === 0
            ? ['decision' => 'edited', 'title' => 'Editado na revisão: ' . mb_substr($item['origin']['title'], 0, 120), 'note' => 'Ajuste de redação.']
            : ['decision' => 'accepted', 'note' => 'Conferido com o texto da norma citada.'];
        $call('PATCH', "/action-plans/{$plan['id']}/items/{$item['id']}", $body);
    }
};

WP_CLI::log('Pré-condições');
$as('gestora.a');
$draft = (array) $call('POST', '/diagnoses', ['questionnaire_id' => $questionnaire->ID])->get_data();
$created['diagnoses'][] = (int) $draft['id'];
$expect('diagnóstico não concluído não gera plano', $call('POST', "/diagnoses/{$draft['id']}/action-plans"), 409);

$diag = $newScoredDiagnosis();

WP_CLI::log('Geração e permissões');
$as('leitor.a');
$expect('leitor não gera plano', $call('POST', "/diagnoses/{$diag}/action-plans"), 403);
$as('gestor.b');
$expect('gestor B não gera plano para diagnóstico de A', $call('POST', "/diagnoses/{$diag}/action-plans"), 404);

$as('gestora.a');
$plan = $expect('gestora A gera plano com IA', $call('POST', "/diagnoses/{$diag}/action-plans", ['context' => 'Município de médio porte.', 'max_actions' => 3]), 201);
$created['plans'][] = (int) ($plan['id'] ?? 0);
$check('plano começa aguardando revisão', ($plan['status'] ?? '') === 'pending_review');
$check('IA devolveu sugestões', count($plan['items'] ?? []) > 0, 'nenhuma sugestão: verifique o acervo do serviço de IA');
$check('métricas da IA registradas', isset($plan['ai']['verification']['citation_validity']));
$check('toda sugestão traz fonte e achado', array_reduce($plan['items'] ?? [], static fn ($ok, $i) => $ok && $i['sources'] !== [] && $i['findings'] !== [], true));
$expect('segundo plano com um em aberto é bloqueado', $call('POST', "/diagnoses/{$diag}/action-plans"), 409);

$as('gestor.b');
$expect('gestor B não lê plano de A', $call('GET', "/action-plans/{$plan['id']}"), 404);
$as('leitor.a');
$expect('leitor A lê o plano', $call('GET', "/action-plans/{$plan['id']}"), 200);
$expect('leitor A não decide itens', $call('PATCH', "/action-plans/{$plan['id']}/items/a1", ['decision' => 'accepted']), 403);

WP_CLI::log('Revisão');
$as('revisor.a');
$expect('rejeição sem motivo é recusada', $call('PATCH', "/action-plans/{$plan['id']}/items/a1", ['decision' => 'rejected']), 422);
$expect('item inexistente', $call('PATCH', "/action-plans/{$plan['id']}/items/a99", ['decision' => 'accepted']), 404);
$expect('concluir com itens pendentes é bloqueado', $call('POST', "/action-plans/{$plan['id']}/complete-review"), 422);
$decideAll($plan, 'revisor.a');
$after = (array) $call('GET', "/action-plans/{$plan['id']}")->get_data();
$check('texto original da IA preservado após edição', $after['items'][0]['origin']['title'] === $plan['items'][0]['origin']['title']);
$check('versão editada guardada à parte', str_starts_with((string) $after['items'][0]['final']['title'], 'Editado na revisão'));
$expect('revisor não aprova', $call('POST', "/action-plans/{$plan['id']}/approve"), 403);
$reviewed = $expect('revisor conclui a revisão', $call('POST', "/action-plans/{$plan['id']}/complete-review"), 200);
$check('status revisado', ($reviewed['status'] ?? '') === 'reviewed');
$expect('plano revisado não aceita novas decisões', $call('PATCH', "/action-plans/{$plan['id']}/items/a1", ['decision' => 'accepted']), 409);

WP_CLI::log('Aprovação');
$as('gestora.a');
$approved = $expect('gestora aprova plano revisado por outra pessoa', $call('POST', "/action-plans/{$plan['id']}/approve"), 200);
$check('plano aprovado com todas as ações decididas', count($approved['final_actions'] ?? []) === count($plan['items']));
$check('ação final indica que foi editada', ($approved['final_actions'][0]['edited'] ?? false) === true);
$check('ação final mantém rastreabilidade', ($approved['final_actions'][0]['sources'] ?? []) !== []);

WP_CLI::log('Separação de funções');
// Mesmos parâmetros do primeiro plano: o serviço de IA responde do cache, sem nova chamada ao LLM
// (evita gastar tokens e esbarrar no limite por minuto do plano gratuito).
$plan2 = $expect('novo plano após o anterior ser finalizado', $call('POST', "/diagnoses/{$diag}/action-plans", ['context' => 'Município de médio porte.', 'max_actions' => 3]), 201);
$check('segundo plano veio do cache da IA', ($plan2['ai']['cache_hit'] ?? false) === true);
if (empty($plan2['id'])) {
    WP_CLI::warning('Sem o segundo plano, as verificações de separação de funções foram puladas.');
    $failures++;
} else {
$created['plans'][] = (int) $plan2['id'];
$decideAll($plan2, 'gestora.a');
$expect('gestora conclui a própria revisão', $call('POST', "/action-plans/{$plan2['id']}/complete-review"), 200);
$seg = $expect('gestora não aprova o que ela mesma revisou', $call('POST', "/action-plans/{$plan2['id']}/approve"), 403);
$check('erro de separação de funções', ($seg['code'] ?? '') === 'eg_segregation');
$expect('rejeição exige motivo', $call('POST', "/action-plans/{$plan2['id']}/reject", ['reason' => 'curto']), 422);
$expect('rejeição com motivo', $call('POST', "/action-plans/{$plan2['id']}/reject", ['reason' => 'Será refeito com o novo acervo de normas.']), 200);
}

global $wpdb;
$events = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT action FROM {$wpdb->prefix}eg_audit_log WHERE object_type = 'eg_action_plan' AND object_id = %d", $plan['id']
));
foreach (['plan_generated', 'plan_item_decided', 'plan_reviewed', 'plan_approved'] as $event) {
    $check("auditoria registrou {$event}", in_array($event, $events, true));
}

// Limpeza
foreach ($created['plans'] as $id) {
    wp_delete_post($id, true);
}
foreach ($created['diagnoses'] as $id) {
    $wpdb->delete("{$wpdb->prefix}eg_responses", ['diagnosis_id' => $id], ['%d']);
    wp_delete_post($id, true);
}

if ($failures > 0) {
    WP_CLI::error("{$failures} verificação(ões) falharam.");
}
WP_CLI::success('Fluxo do plano de ação: todas as verificações passaram.');
