<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

final class DiagnosisResult
{
    /**
     * @param array<string, array{label:string, score:?float, answered:bool}> $dimensions
     * @param list<array<string,mixed>> $findings
     */
    public function __construct(
        public readonly float $score,
        public readonly RiskLevel $level,
        public readonly array $dimensions,
        public readonly array $findings,
        public readonly int $criticalCount,
        public readonly string $ruleVersion,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'score'          => $this->score,
            'risk_level'     => $this->level->value,
            'risk_label'     => $this->level->label(),
            'critical_count' => $this->criticalCount,
            'dimensions'     => $this->dimensions,
            'findings'       => $this->findings,
            'rule_version'   => $this->ruleVersion,
        ];
    }
}
