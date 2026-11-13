<?php

declare(strict_types=1);

namespace EquiGov\Core\Diagnosis;

/**
 * Pergunta imutável. Tipos:
 *  - single_choice: cada opção tem um risco 0..1 definido por quem escreveu o questionário.
 *  - multi_choice:  opções são boas práticas; risco = 1 - (marcadas / total).
 *
 * @phpstan-type Option array{label:string, risk:float, finding:?string, critical:bool, reference:?string}
 */
final class Question
{
    public const SINGLE = 'single_choice';
    public const MULTI  = 'multi_choice';
    public const TYPES  = [self::SINGLE, self::MULTI];

    /**
     * @param array<string, Option> $options
     * @param array<string,mixed>|null $showIf
     */
    public function __construct(
        public readonly string $key,
        public readonly string $dimension,
        public readonly string $type,
        public readonly string $text,
        public readonly int $weight,
        public readonly bool $required,
        public readonly array $options,
        public readonly ?array $showIf = null,
        public readonly ?string $finding = null,
        public readonly ?string $reference = null,
    ) {
    }

    public function isMulti(): bool
    {
        return $this->type === self::MULTI;
    }

    public function hasOption(string $value): bool
    {
        return array_key_exists($value, $this->options);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $options = [];
        foreach ($this->options as $value => $o) {
            $options[] = ['value' => $value, 'label' => $o['label']];
        }
        return array_filter([
            'key'       => $this->key,
            'dimension' => $this->dimension,
            'type'      => $this->type,
            'text'      => $this->text,
            'required'  => $this->required,
            'options'   => $options,
            'show_if'   => $this->showIf,
        ], static fn ($v) => $v !== null);
    }
}
