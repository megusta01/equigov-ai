<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Diagnosis\QuestionnaireSchema;
use EquiGov\Core\Diagnosis\SchemaException;
use PHPUnit\Framework\TestCase;

final class QuestionnaireSchemaTest extends TestCase
{
    public function testShippedQuestionnaireIsValid(): void
    {
        // Protege o questionário de exemplo contra edições que o quebrem.
        $schema = QuestionnaireSchema::fromJson((string) file_get_contents(Fixtures::SHIPPED));
        self::assertGreaterThanOrEqual(10, count($schema->questions));
        self::assertSame(1, $schema->version);
    }

    public function testRejectsForwardReferenceInShowIf(): void
    {
        $data = Fixtures::smallArray();
        $data['questions'][0]['show_if'] = ['question' => 'q3', 'op' => 'not_empty'];

        $errors = $this->errorsFor($data);
        self::assertStringContainsString('pergunta anterior', implode(' ', $errors));
    }

    public function testRejectsConditionOnUnknownOption(): void
    {
        $data = Fixtures::smallArray();
        $data['questions'][1]['show_if']['value'] = 'talvez';

        self::assertStringContainsString('não é opção de q1', implode(' ', $this->errorsFor($data)));
    }

    public function testReportsAllErrorsAtOnce(): void
    {
        $data = Fixtures::smallArray();
        $data['questions'][1]['weight'] = 9;
        $data['questions'][2]['dimension'] = 'inexistente';
        $data['questions'][2]['options'][1]['value'] = 'p1';

        $errors = $this->errorsFor($data);
        self::assertCount(3, $errors, implode(' | ', $errors));
    }

    public function testRejectsDuplicateKey(): void
    {
        $data = Fixtures::smallArray();
        $data['questions'][2]['key'] = 'q1';

        self::assertStringContainsString('duplicada', implode(' ', $this->errorsFor($data)));
    }

    public function testSingleChoiceRequiresRiskBetweenZeroAndOne(): void
    {
        $data = Fixtures::smallArray();
        $data['questions'][0]['options'][0]['risk'] = 1.5;

        self::assertStringContainsString('risk', implode(' ', $this->errorsFor($data)));
    }

    public function testPublicArrayHidesRiskAndFindings(): void
    {
        $json = json_encode(Fixtures::small()->toPublicArray());
        self::assertStringNotContainsString('risk', (string) $json);
        self::assertStringNotContainsString('Falta Q1', (string) $json);
    }

    public function testInvalidJson(): void
    {
        $this->expectException(SchemaException::class);
        QuestionnaireSchema::fromJson('{nope');
    }

    /** @return list<string> */
    private function errorsFor(array $data): array
    {
        try {
            QuestionnaireSchema::fromArray($data);
        } catch (SchemaException $e) {
            return $e->errors;
        }
        self::fail('Schema inválido foi aceito.');
    }
}
