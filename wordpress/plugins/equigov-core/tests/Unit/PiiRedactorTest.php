<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Support\PiiRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PiiRedactorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function personal(): iterable
    {
        yield 'e-mail'               => ['Falar com maria.souza@prefeitura.gov.br', '[email removido]'];
        yield 'CPF formatado'        => ['CPF 123.456.789-09 do responsável', '[cpf removido]'];
        yield 'CPF só dígitos'       => ['CPF 12345678909', '[cpf removido]'];
        yield 'CNPJ'                 => ['CNPJ 12.345.678/0001-95', '[cnpj removido]'];
        yield 'celular com DDD'      => ['Ligar (61) 99876-5432', '[telefone removido]'];
        yield 'fixo sem formatação'  => ['Ramal 6133445566', '[telefone removido]'];
    }

    #[DataProvider('personal')]
    public function testRemovesPersonalData(string $input, string $marker): void
    {
        $r = PiiRedactor::redact($input);
        self::assertStringContainsString($marker, $r['text']);
        self::assertSame(1, $r['count']);
    }

    public function testKeepsInstitutionalContext(): void
    {
        $text = 'Município de 80 mil habitantes, 2 unidades, 35% da população preta ou parda, orçamento de 2026.';
        $r = PiiRedactor::redact($text);
        self::assertSame($text, $r['text']);
        self::assertSame(0, $r['count']);
    }

    public function testCountsSeveralOccurrences(): void
    {
        self::assertSame(2, PiiRedactor::redact('a@b.gov.br e c@d.gov.br')['count']);
    }
}
