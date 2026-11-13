<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

/**
 * Pontuação determinística e explicável. Nenhuma IA aqui.
 *
 *   risco(pergunta)  = single: risco da opção | multi: 1 - marcadas/total
 *   pontuação(dim)   = Σ(peso × risco) / Σ(peso) × 100   (perguntas visíveis)
 *   pontuação geral  = mesma fórmula sobre todas as perguntas visíveis
 *   nível            = faixa da pontuação, elevado para no mínimo "Alto"
 *                      se houver alguma resposta marcada como crítica
 *
 * Cada achado guarda pergunta, resposta, regra e contribuição em pontos:
 * é o primeiro elo da cadeia de rastreabilidade.
 */
final class ScoringEngine
{
    public const FINDING_THRESHOLD = 0.5;
    public const RULE_VERSION      = 'scoring-v1';

    /** @param array<string,string|list<string>> $visible respostas visíveis e validadas */
    public static function score(QuestionnaireSchema $schema, array $visible): DiagnosisResult
    {
        $dimWeighted = array_fill_keys(array_keys($schema->dimensions), 0.0);
        $dimWeights  = array_fill_keys(array_keys($schema->dimensions), 0);
        $findings    = [];
        $criticals   = 0;
        $totalWeight = 0;
        $totalRisk   = 0.0;

        foreach ($visible as $key => $answer) {
            $q = $schema->question($key);
            if ($q === null) {
                continue;
            }

            [$risk, $finding] = $q->isMulti()
                ? self::multi($q, (array) $answer)
                : self::single($q, (string) $answer);

            $dimWeighted[$q->dimension] += $q->weight * $risk;
            $dimWeights[$q->dimension]  += $q->weight;
            $totalRisk                  += $q->weight * $risk;
            $totalWeight                += $q->weight;

            $critical = !$q->isMulti() && $q->options[$answer]['critical'];
            $criticals += $critical ? 1 : 0;

            if ($risk >= self::FINDING_THRESHOLD || $critical) {
                $findings[] = $finding + [
                    'question_key'  => $q->key,
                    'question_text' => $q->text,
                    'dimension'     => $q->dimension,
                    'weight'        => $q->weight,
                    'risk'          => round($risk, 3),
                    'critical'      => $critical,
                ];
            }
        }

        $overall = $totalWeight > 0 ? round($totalRisk / $totalWeight * 100, 1) : 0.0;

        // Contribuição = quantos pontos da nota geral este item explica.
        foreach ($findings as &$f) {
            $f['contribution'] = $totalWeight > 0 ? round($f['weight'] * $f['risk'] / $totalWeight * 100, 1) : 0.0;
        }
        unset($f);
        usort($findings, static fn ($a, $b) => [$b['critical'], $b['contribution']] <=> [$a['critical'], $a['contribution']]);

        $dimensions = [];
        foreach ($schema->dimensions as $dk => $label) {
            $dimensions[$dk] = [
                'label'    => $label,
                'score'    => $dimWeights[$dk] > 0 ? round($dimWeighted[$dk] / $dimWeights[$dk] * 100, 1) : null,
                'answered' => $dimWeights[$dk] > 0,
            ];
        }

        $level = RiskLevel::fromScore($overall);
        if ($criticals > 0) {
            $level = $level->atLeast(RiskLevel::High);
        }

        return new DiagnosisResult($overall, $level, $dimensions, $findings, $criticals, self::RULE_VERSION);
    }

    /** @return array{0: float, 1: array<string,mixed>} */
    private static function single(Question $q, string $answer): array
    {
        $o = $q->options[$answer];
        return [$o['risk'], [
            'answer'    => $o['label'],
            'rule'      => sprintf('Opção "%s" tem risco %.2f (peso %d).', $o['label'], $o['risk'], $q->weight),
            'message'   => $o['finding'] ?? $q->finding ?? 'Resposta indica risco de desigualdade no acesso.',
            'reference' => $o['reference'] ?? $q->reference,
        ]];
    }

    /** @param list<string> $selected @return array{0: float, 1: array<string,mixed>} */
    private static function multi(Question $q, array $selected): array
    {
        $total   = count($q->options);
        $missing = array_diff_key($q->options, array_flip($selected));
        $risk    = $total > 0 ? count($missing) / $total : 0.0;

        return [$risk, [
            'answer'    => count($selected) . " de {$total} práticas adotadas",
            'rule'      => sprintf('Cobertura %d/%d → risco %.2f (peso %d).', count($selected), $total, $risk, $q->weight),
            'message'   => ($q->finding ?? 'Práticas ausentes') . ': ' . implode(', ', array_column($missing, 'label')) . '.',
            'reference' => $q->reference,
            'missing'   => array_keys($missing),
        ]];
    }
}
