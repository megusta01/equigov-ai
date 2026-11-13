<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Auth\Roles;
use EquiGov\Core\PostTypes\Registrar;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function testHierarchyIsCumulative(): void
    {
        $map = Roles::map();
        $order = ['eg_reader', 'eg_reviewer', 'eg_manager', 'eg_platform_admin'];

        for ($i = 1; $i < count($order); $i++) {
            $lower  = $map[$order[$i - 1]]['caps'];
            $higher = $map[$order[$i]]['caps'];
            self::assertSame([], array_diff($lower, $higher), "{$order[$i]} deve conter as capacidades de {$order[$i - 1]}");
        }
    }

    public function testReaderCannotWriteOrApprove(): void
    {
        $caps = Roles::map()['eg_reader']['caps'];
        self::assertNotContains(Roles::MANAGE_DIAGNOSES, $caps);
        self::assertNotContains(Roles::APPROVE_ACTION_PLANS, $caps);
    }

    public function testReviewerCannotApprove(): void
    {
        // Separação de funções: quem revisa a sugestão da IA não é quem aprova.
        self::assertNotContains(Roles::APPROVE_ACTION_PLANS, Roles::map()['eg_reviewer']['caps']);
    }

    public function testOnlyPlatformAdminManagesInstitutions(): void
    {
        foreach (Roles::map() as $slug => $role) {
            $has = in_array(Roles::MANAGE_INSTITUTIONS, $role['caps'], true);
            self::assertSame($slug === 'eg_platform_admin', $has, $slug);
        }
    }

    public function testPostTypeCapabilitiesNeverFallBackToCoreCaps(): void
    {
        $caps = Registrar::capabilities(Roles::MANAGE_DIAGNOSES, Roles::VIEW_DIAGNOSES);
        foreach ($caps as $primitive => $mapped) {
            self::assertStringStartsWith('eg_', $mapped, "{$primitive} não pode cair em capacidade nativa");
        }
        self::assertArrayHasKey('create_posts', $caps);
    }
}
