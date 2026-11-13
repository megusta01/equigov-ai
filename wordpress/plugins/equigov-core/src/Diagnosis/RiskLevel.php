<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

enum RiskLevel: string
{
    case Low      = 'low';
    case Moderate = 'moderate';
    case High     = 'high';
    case Critical = 'critical';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 75 => self::Critical,
            $score >= 50 => self::High,
            $score >= 25 => self::Moderate,
            default      => self::Low,
        };
    }

    public function atLeast(self $floor): self
    {
        return $this->rank() >= $floor->rank() ? $this : $floor;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0, self::Moderate => 1, self::High => 2, self::Critical => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixo', self::Moderate => 'Moderado', self::High => 'Alto', self::Critical => 'Crítico',
        };
    }
}
