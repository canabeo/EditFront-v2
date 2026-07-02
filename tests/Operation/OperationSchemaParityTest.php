<?php

declare(strict_types=1);

namespace EditFront\Tests\Operation;

use EditFront\Document\Annotator;
use EditFront\Operation\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * THE whitelist-drift guard (§4.2, анти-паттерн №1): the fixtures file holds
 * payloads exactly as preview-inject.js emits them. Every fixture must pass
 * the server schema (no unknown/missing fields) AND apply cleanly; every
 * registered op must have at least one fixture. Plugin ops join the same
 * registry in C3 and fall under this test automatically.
 */
final class OperationSchemaParityTest extends TestCase
{
    /** @var array<string, list<array{target: ?string, forward: array<string, mixed>}>> */
    private array $fixtures;

    protected function setUp(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/fixtures/client-emissions.json');
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        unset($decoded['_comment']);
        $this->fixtures = $decoded;
    }

    public function test_every_registered_op_has_a_fixture(): void
    {
        foreach (array_keys(ef2_registry()->all()) as $key) {
            $this->assertArrayHasKey($key, $this->fixtures, "no client fixture for op '$key'");
        }
    }

    public function test_every_fixture_op_is_registered(): void
    {
        $registry = ef2_registry();
        foreach (array_keys($this->fixtures) as $key) {
            $this->assertNotNull($registry->get($key), "fixture references unknown op '$key'");
        }
    }

    public function test_fixtures_pass_schema_after_sanitize(): void
    {
        $registry = ef2_registry();
        $validator = new SchemaValidator();
        foreach ($this->fixtures as $key => $cases) {
            $spec = $registry->get($key);
            foreach ($cases as $i => $case) {
                $forward = $spec->sanitize((array) $case['forward']);
                $errors = $validator->validate($spec->schema(), $forward);
                $this->assertSame([], $errors, "fixture $key#$i fails schema: " . implode('; ', $errors));
            }
        }
    }

    public function test_fixtures_apply_on_reference_document(): void
    {
        $registry = ef2_registry();
        $annotator = new Annotator();
        foreach ($this->fixtures as $key => $cases) {
            $spec = $registry->get($key);
            foreach ($cases as $i => $case) {
                // fresh doc per fixture — ops mutate it
                $doc = ef2_doc(
                    '<div data-cms-id="cms-aaaaaaaaaaaa" title="t" style="color: red">first</div>'
                    . '<p data-cms-id="cms-dddddddddddd">second</p>'
                    . '<img data-cms-id="cms-eeeeeeeeeeee" src="x.png" alt="">'
                );
                $forward = $spec->sanitize((array) $case['forward']);
                $target = null;
                if ($spec->targetRequired()) {
                    $target = $annotator->findById($doc, (string) $case['target']);
                    $this->assertNotNull($target, "fixture $key#$i: target missing in reference doc");
                }
                $spec->apply($doc, $target, $forward); // must not throw
                $this->addToAssertionCount(1);
            }
        }
    }
}
