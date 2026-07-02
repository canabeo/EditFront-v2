<?php

declare(strict_types=1);

namespace EditFront\Operation;

/**
 * Minimal declarative field validator (no external JSON-schema dependency).
 * Unknown keys are errors — this is the structural whitelist-drift cure:
 * a field the schema does not know cannot silently pass through.
 *
 * Field spec: ['type' => 'string|int|number|bool|enum|object|list|any',
 *   'required' => bool, 'nullable' => bool, 'maxLen' => int, 'pattern' => regex,
 *   'values' => [...] (enum), 'min'/'max' => int, 'fields' => [...] (object),
 *   'of' => fieldSpec (list), 'maxItems' => int]
 *
 * 'any' accepts any JSON value — used by plugin props ops where the deep type
 * (number/string/object) is validated against the kind's PropsSchema at apply
 * time, not by this structural gate (§6.4.1).
 */
final class SchemaValidator
{
    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $data
     * @return list<string> errors (empty = valid)
     */
    public function validate(array $fields, array $data, string $path = ''): array
    {
        $errors = [];

        foreach ($data as $key => $value) {
            if (!array_key_exists((string) $key, $fields)) {
                $errors[] = "unknown field: {$path}{$key}";
            }
        }

        foreach ($fields as $name => $spec) {
            $label = $path . $name;
            $exists = array_key_exists($name, $data);
            if (!$exists) {
                if (($spec['required'] ?? false) === true) {
                    $errors[] = "missing required field: {$label}";
                }
                continue;
            }
            $value = $data[$name];
            if ($value === null) {
                if (($spec['nullable'] ?? false) !== true) {
                    $errors[] = "field must not be null: {$label}";
                }
                continue;
            }

            switch ($spec['type'] ?? 'string') {
                case 'string':
                    if (!is_string($value)) {
                        $errors[] = "expected string: {$label}";
                        break;
                    }
                    if (isset($spec['maxLen']) && strlen($value) > (int) $spec['maxLen']) {
                        $errors[] = "too long: {$label}";
                    }
                    if (isset($spec['pattern']) && preg_match((string) $spec['pattern'], $value) !== 1) {
                        $errors[] = "pattern mismatch: {$label}";
                    }
                    break;

                case 'int':
                    if (!is_int($value)) {
                        $errors[] = "expected int: {$label}";
                        break;
                    }
                    if (isset($spec['min']) && $value < (int) $spec['min']) {
                        $errors[] = "below min: {$label}";
                    }
                    if (isset($spec['max']) && $value > (int) $spec['max']) {
                        $errors[] = "above max: {$label}";
                    }
                    break;

                case 'number':
                    if (!is_int($value) && !is_float($value)) {
                        $errors[] = "expected number: {$label}";
                    }
                    break;

                case 'bool':
                    if (!is_bool($value)) {
                        $errors[] = "expected bool: {$label}";
                    }
                    break;

                case 'enum':
                    if (!in_array($value, (array) ($spec['values'] ?? []), true)) {
                        $errors[] = "not in enum: {$label}";
                    }
                    break;

                case 'any':
                    // accepted as-is; deep validation happens against PropsSchema
                    break;

                case 'object':
                    if (!is_array($value)) {
                        $errors[] = "expected object: {$label}";
                        break;
                    }
                    $errors = array_merge(
                        $errors,
                        $this->validate((array) ($spec['fields'] ?? []), $value, $label . '.')
                    );
                    break;

                case 'list':
                    if (!is_array($value) || $value !== array_values($value)) {
                        $errors[] = "expected list: {$label}";
                        break;
                    }
                    if (isset($spec['maxItems']) && count($value) > (int) $spec['maxItems']) {
                        $errors[] = "too many items: {$label}";
                        break;
                    }
                    $of = (array) ($spec['of'] ?? ['type' => 'string']);
                    foreach ($value as $i => $item) {
                        $errors = array_merge(
                            $errors,
                            $this->validate(['item' => $of], ['item' => $item], $label . "[$i].")
                        );
                    }
                    break;

                default:
                    $errors[] = "unknown schema type for: {$label}";
            }
        }

        return $errors;
    }
}
