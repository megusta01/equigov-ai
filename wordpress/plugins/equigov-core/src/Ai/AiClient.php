<?php

declare(strict_types=1);

namespace EquiGov\Core\Ai;

use EquiGov\Core\Support\DomainError;

/**
 * Cliente do serviço de IA. Traduz falhas técnicas em mensagens que o gestor entende,
 * mantendo um código estável para logs e para a interface.
 *
 * Timeout de 55 s: abaixo do limite do PHP-FPM/Nginx (60 s). Se estourar, o serviço de IA
 * provavelmente termina e guarda em cache; a nova tentativa do usuário volta instantânea.
 */
final class AiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 55,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            defined('EQUIGOV_AI_SERVICE_URL') ? (string) EQUIGOV_AI_SERVICE_URL : '',
            defined('EQUIGOV_AI_SERVICE_TOKEN') ? (string) EQUIGOV_AI_SERVICE_TOKEN : '',
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && strlen($this->token) >= 16;
    }

    /**
     * @param array<string,mixed> $diagnosis {id, score, risk_level, findings[]}
     * @return array<string,mixed>
     */
    public function generatePlan(array $diagnosis, ?string $context, int $maxActions): array
    {
        $data = $this->request('POST', '/v1/plans', [
            'diagnosis'   => $diagnosis,
            'context'     => $context ?: null,
            'max_actions' => $maxActions,
        ]);
        if (!isset($data['actions'], $data['meta']) || !is_array($data['actions'])) {
            throw new DomainError('eg_ai_bad_response', 'O serviço de IA respondeu em formato inesperado.', 502);
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new DomainError('eg_ai_not_configured', 'O serviço de IA não está configurado nesta instalação.', 503);
        }

        $requestId = substr(bin2hex(random_bytes(8)), 0, 12);
        $args = [
            'method'  => $method,
            'timeout' => $this->timeout,
            'headers' => [
                'X-Service-Token' => $this->token,
                'X-Request-Id'    => $requestId,
                'Accept'          => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body']                    = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $response = wp_remote_request(rtrim($this->baseUrl, '/') . $path, $args);

        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            error_log("[equigov] ai_unreachable request_id={$requestId} {$msg}");
            if (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
                throw new DomainError('eg_ai_timeout', 'O serviço de IA demorou demais. Tente de novo em instantes: se o plano terminou de ser gerado, ele virá do cache.', 504);
            }
            throw new DomainError('eg_ai_unreachable', 'Não foi possível contatar o serviço de IA.', 502);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode((string) wp_remote_retrieve_body($response), true);
        $data   = is_array($data) ? $data : [];

        if ($status >= 200 && $status < 300) {
            return $data;
        }

        // FastAPI devolve {"code","message"} (erros de IA) ou {"detail": {...}} (HTTPException).
        $code = (string) ($data['code'] ?? $data['detail']['code'] ?? 'unknown');
        error_log("[equigov] ai_error request_id={$requestId} status={$status} code={$code}");

        throw match (true) {
            $code === 'empty_library'      => new DomainError('eg_ai_empty_library', 'A biblioteca de referências está vazia. Peça ao administrador para carregar os documentos.', 409),
            $code === 'llm_rate_limited'   => new DomainError('eg_ai_busy', 'O provedor de IA atingiu o limite de uso. Tente novamente em alguns minutos.', 503),
            $code === 'llm_quota_too_small'=> new DomainError('eg_ai_quota', 'O pedido ficou maior que o limite por minuto do plano do provedor de IA. Peça ao administrador para ajustar a configuração.', 503),
            $code === 'llm_not_configured' => new DomainError('eg_ai_not_configured', 'O provedor de IA não está configurado.', 503),
            $code === 'llm_timeout'        => new DomainError('eg_ai_timeout', 'O provedor de IA não respondeu a tempo. Tente novamente.', 504),
            $status === 401                => new DomainError('eg_ai_auth', 'Falha de autenticação entre o WordPress e o serviço de IA (token).', 502),
            $status === 422                => new DomainError('eg_ai_invalid_request', 'O serviço de IA recusou os dados do diagnóstico.', 502),
            default                        => new DomainError('eg_ai_error', 'O serviço de IA falhou ao gerar o plano.', 502, ['upstream_code' => $code]),
        };
    }
}
