<?php

declare(strict_types=1);

namespace EquiGov\Core\Support;

/**
 * Erro de regra de negócio com código estável e status HTTP.
 * Os controllers convertem em WP_Error; o serviço não conhece HTTP além do número.
 */
final class DomainError extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Recurso'): self
    {
        return new self('eg_not_found', "{$what} não encontrado.", 404);
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error($this->errorCode, $this->getMessage(), ['status' => $this->status] + $this->details);
    }
}
