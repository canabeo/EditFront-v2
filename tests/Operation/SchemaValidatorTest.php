<?php

declare(strict_types=1);

namespace EditFront\Tests\Operation;

use EditFront\Operation\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    public function test_unknown_field_is_error(): void
    {
        $errors = $this->validator->validate(
            ['name' => ['type' => 'string', 'required' => true]],
            ['name' => 'x', 'sneaky' => 1]
        );
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('unknown field: sneaky', $errors[0]);
    }

    public function test_missing_required_is_error(): void
    {
        $errors = $this->validator->validate(
            ['name' => ['type' => 'string', 'required' => true]],
            []
        );
        $this->assertStringContainsString('missing required', $errors[0]);
    }

    public function test_types_and_constraints(): void
    {
        $fields = [
            's' => ['type' => 'string', 'maxLen' => 3],
            'i' => ['type' => 'int', 'min' => 0, 'max' => 10],
            'b' => ['type' => 'bool'],
            'e' => ['type' => 'enum', 'values' => ['a', 'b']],
        ];
        $this->assertSame([], $this->validator->validate($fields, ['s' => 'ab', 'i' => 5, 'b' => true, 'e' => 'a']));

        $this->assertNotSame([], $this->validator->validate($fields, ['s' => 'toolong']));
        $this->assertNotSame([], $this->validator->validate($fields, ['i' => 11]));
        $this->assertNotSame([], $this->validator->validate($fields, ['i' => '5']));
        $this->assertNotSame([], $this->validator->validate($fields, ['b' => 1]));
        $this->assertNotSame([], $this->validator->validate($fields, ['e' => 'c']));
    }

    public function test_pattern(): void
    {
        $fields = ['id' => ['type' => 'string', 'pattern' => '/^cms-[0-9a-f]{12}$/']];
        $this->assertSame([], $this->validator->validate($fields, ['id' => 'cms-aaaaaaaaaaaa']));
        $this->assertNotSame([], $this->validator->validate($fields, ['id' => 'nope']));
    }

    public function test_nested_object_and_nullable(): void
    {
        $fields = [
            'place' => ['type' => 'object', 'required' => true, 'fields' => [
                'parentId' => ['type' => 'string', 'nullable' => true, 'pattern' => '/^cms-[0-9a-f]{12}$/'],
                'index' => ['type' => 'int', 'required' => true, 'min' => 0],
            ]],
        ];
        $this->assertSame([], $this->validator->validate($fields, ['place' => ['parentId' => null, 'index' => 0]]));
        $this->assertSame([], $this->validator->validate($fields, ['place' => ['parentId' => 'cms-aaaaaaaaaaaa', 'index' => 2]]));

        $errors = $this->validator->validate($fields, ['place' => ['parentId' => null, 'index' => -1, 'junk' => 1]]);
        $this->assertCount(2, $errors); // below min + unknown nested field
    }

    public function test_list_type(): void
    {
        $fields = ['classes' => ['type' => 'list', 'of' => ['type' => 'string', 'maxLen' => 5], 'maxItems' => 2]];
        $this->assertSame([], $this->validator->validate($fields, ['classes' => ['a', 'b']]));
        $this->assertNotSame([], $this->validator->validate($fields, ['classes' => ['a', 'b', 'c']]));
        $this->assertNotSame([], $this->validator->validate($fields, ['classes' => ['toolong!']]));
        $this->assertNotSame([], $this->validator->validate($fields, ['classes' => ['k' => 'v']]));
    }
}
