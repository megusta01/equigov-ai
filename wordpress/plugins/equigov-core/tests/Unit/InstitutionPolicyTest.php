<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Auth\InstitutionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstitutionPolicyTest extends TestCase
{
    /** @return iterable<string, array{int,int,bool,bool}> */
    public static function cases(): iterable
    {
        yield 'mesma instituição'                 => [1, 1, false, true];
        yield 'outra instituição é negada'        => [1, 2, false, false];
        yield 'usuário sem vínculo é negado'      => [0, 1, false, false];
        yield 'registro sem instituição é negado' => [1, 0, false, false];
        yield 'ids negativos são negados'         => [-1, -1, false, false];
        yield 'admin da plataforma acessa tudo'   => [0, 2, true, true];
    }

    #[DataProvider('cases')]
    public function testCanAccess(int $user, int $resource, bool $admin, bool $expected): void
    {
        self::assertSame($expected, InstitutionPolicy::canAccess($user, $resource, $admin));
    }
}
