<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

final class SchemaException extends \InvalidArgumentException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Questionário inválido: ' . implode('; ', $errors));
    }
}
