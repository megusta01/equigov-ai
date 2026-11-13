<?php

declare(strict_types=1);

namespace EquiGov\Core\Auth;

/**
 * Regra de isolamento multi-institucional, sem dependência do WordPress.
 * Negar por padrão: registro sem instituição ou usuário sem vínculo = sem acesso.
 */
final class InstitutionPolicy
{
    public static function canAccess(int $userInstitutionId, int $resourceInstitutionId, bool $isPlatformAdmin): bool
    {
        if ($isPlatformAdmin) {
            return true;
        }
        if ($userInstitutionId <= 0 || $resourceInstitutionId <= 0) {
            return false;
        }
        return $userInstitutionId === $resourceInstitutionId;
    }
}
