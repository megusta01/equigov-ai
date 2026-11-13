<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

/**
 * Questionário validado. Construir via fromArray(): ou o schema é inteiramente
 * válido, ou nada é criado (todos os erros são reportados de uma vez).
 *
 * Regra que elimina ciclos: show_if só pode referenciar perguntas ANTERIORES.
 * Assim a visibilidade é calculada numa única passada, em ordem.
 */
final class QuestionnaireSchema
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,62}$/';

    /**
     * @param array<string,string>   $dimensions
     * @param array<string,Question> $questions  em ordem de exibição
     */
    private function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $title,
        public readonly array $dimensions,
        public readonly array $questions,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SchemaException(['JSON inválido: ' . $e->getMessage()]);
        }
        return self::fromArray(is_array($data) ? $data : []);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $errors = [];

        $key     = is_string($data['key'] ?? null) ? $data['key'] : '';
        $version = is_int($data['version'] ?? null) ? $data['version'] : 0;
        $title   = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $dims    = is_array($data['dimensions'] ?? null) ? $data['dimensions'] : [];
        $rawQs   = is_array($data['questions'] ?? null) ? $data['questions'] : [];

        if (!preg_match(self::KEY_PATTERN, $key)) {
            $errors[] = 'key deve ser snake_case';
        }
        if ($version < 1) {
            $errors[] = 'version deve ser inteiro >= 1';
        }
        if ($title === '') {
            $errors[] = 'title é obrigatório';
        }
        if ($dims === [] || array_filter($dims, 'is_string') !== $dims) {
            $errors[] = 'dimensions deve ser um mapa chave => rótulo';
        }
        if ($rawQs === [] || !array_is_list($rawQs)) {
            $errors[] = 'questions deve ser uma lista não vazia';
        }

        $questions = [];
        foreach ($rawQs as $i => $raw) {
            $q = self::parseQuestion(is_array($raw) ? $raw : [], "questions[{$i}]", $dims, $questions, $errors);
            if ($q !== null) {
                $questions[$q->key] = $q;
            }
        }

        if ($errors !== []) {
            throw new SchemaException($errors);
        }

        return new self($key, $version, $title, $dims, $questions);
    }

    /**
     * @param array<string,mixed>    $raw
     * @param array<string,string>   $dims
     * @param array<string,Question> $previous
     * @param list<string>           $errors
     */
    private static function parseQuestion(array $raw, string $path, array $dims, array $previous, array &$errors): ?Question
    {
        $before = count($errors);

        $key  = is_string($raw['key'] ?? null) ? $raw['key'] : '';
        $type = $raw['type'] ?? null;
        $dim  = $raw['dimension'] ?? null;
        $text = is_string($raw['text'] ?? null) ? trim($raw['text']) : '';
        $wt   = $raw['weight'] ?? 1;

        if (!preg_match(self::KEY_PATTERN, $key)) {
            $errors[] = "{$path}.key inválida";
        } elseif (isset($previous[$key])) {
            $errors[] = "{$path}.key duplicada: {$key}";
        }
        if (!in_array($type, Question::TYPES, true)) {
            $errors[] = "{$path}.type deve ser um de: " . implode(', ', Question::TYPES);
        }
        if (!is_string($dim) || !isset($dims[$dim])) {
            $errors[] = "{$path}.dimension desconhecida";
        }
        if ($text === '') {
            $errors[] = "{$path}.text é obrigatório";
        }
        if (!is_int($wt) || $wt < 1 || $wt > 5) {
            $errors[] = "{$path}.weight deve ser inteiro entre 1 e 5";
        }

        $options = self::parseOptions($raw['options'] ?? null, $type === Question::MULTI, $path, $errors);

        $showIf = $raw['show_if'] ?? null;
        if ($showIf !== null) {
            if (!is_array($showIf)) {
                $errors[] = "{$path}.show_if deve ser objeto";
            } else {
                foreach (ConditionEvaluator::validate($showIf, $previous) as $e) {
                    $errors[] = "{$path}.show_if: {$e}";
                }
            }
        }

        if (count($errors) > $before) {
            return null;
        }

        return new Question(
            key: $key,
            dimension: $dim,
            type: $type,
            text: $text,
            weight: $wt,
            required: (bool) ($raw['required'] ?? true),
            options: $options,
            showIf: $showIf,
            finding: is_string($raw['finding'] ?? null) ? $raw['finding'] : null,
            reference: is_string($raw['reference'] ?? null) ? $raw['reference'] : null,
        );
    }

    /**
     * @param list<string> $errors
     * @return array<string, array{label:string, risk:float, finding:?string, critical:bool, reference:?string}>
     */
    private static function parseOptions(mixed $raw, bool $multi, string $path, array &$errors): array
    {
        if (!is_array($raw) || count($raw) < 2 || !array_is_list($raw)) {
            $errors[] = "{$path}.options deve ser lista com pelo menos 2 itens";
            return [];
        }

        $out = [];
        foreach ($raw as $j => $o) {
            $p     = "{$path}.options[{$j}]";
            $value = is_array($o) && is_string($o['value'] ?? null) ? $o['value'] : '';
            $label = is_array($o) && is_string($o['label'] ?? null) ? $o['label'] : '';
            $risk  = is_array($o) ? ($o['risk'] ?? null) : null;

            if ($value === '' || isset($out[$value])) {
                $errors[] = "{$p}.value vazio ou duplicado";
                continue;
            }
            if ($label === '') {
                $errors[] = "{$p}.label é obrigatório";
            }
            // Em multi_choice o risco vem da cobertura; em single_choice é obrigatório.
            if (!$multi && (!is_int($risk) && !is_float($risk) || $risk < 0 || $risk > 1)) {
                $errors[] = "{$p}.risk deve ser número entre 0 e 1";
                $risk = 0;
            }

            $out[$value] = [
                'label'     => $label,
                'risk'      => $multi ? 0.0 : (float) $risk,
                'finding'   => is_string($o['finding'] ?? null) ? $o['finding'] : null,
                'critical'  => (bool) ($o['critical'] ?? false),
                'reference' => is_string($o['reference'] ?? null) ? $o['reference'] : null,
            ];
        }
        return $out;
    }

    public function question(string $key): ?Question
    {
        return $this->questions[$key] ?? null;
    }

    /** Representação pública (sem riscos nem textos de achados: quem responde não vê a régua). */
    public function toPublicArray(): array
    {
        return [
            'key'        => $this->key,
            'version'    => $this->version,
            'title'      => $this->title,
            'dimensions' => $this->dimensions,
            'questions'  => array_values(array_map(static fn (Question $q) => $q->toArray(), $this->questions)),
        ];
    }
}
