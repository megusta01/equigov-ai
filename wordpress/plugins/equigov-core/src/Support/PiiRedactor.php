<?php

declare(strict_types=1);

namespace EquiGov\Core\Support;

/**
 * Remove padrões de dado pessoal de texto livre antes de enviá-lo a um provedor externo.
 *
 * Cobre o que tem formato reconhecível: e-mail, CPF, CNPJ e telefone brasileiro.
 * NÃO detecta nomes de pessoas: por isso a interface orienta a não escrevê-los,
 * e a documentação não promete mais do que isto.
 */
final class PiiRedactor
{
    private const PATTERNS = [
        'email'    => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u',
        'cnpj'     => '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/u',
        'cpf'      => '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/u',
        'telefone' => '/(?<!\d)(?:\+?55\s?)?(?:\(?\d{2}\)?\s?)?9?\d{4}[-\s]?\d{4}(?!\d)/u',
    ];

    /** @return array{text: string, count: int} */
    public static function redact(string $text): array
    {
        $count = 0;
        foreach (self::PATTERNS as $kind => $pattern) {
            $text = (string) preg_replace_callback($pattern, static function () use ($kind, &$count): string {
                $count++;
                return "[{$kind} removido]";
            }, $text);
        }
        return ['text' => $text, 'count' => $count];
    }
}
