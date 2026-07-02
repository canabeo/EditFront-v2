<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/**
 * The single source of a kind's field shape (§6.4.1): the core generates the
 * client form, the server validator and the preview mirror all from THIS — no
 * hand-written second whitelist, so plugin fields cannot drift.
 *
 * Supported field types: enum, bool, number, string, richtext, color,
 * object{shape}, array{of, max, shape}. Path semantics: "tiers.1.price"
 * addresses props.tiers[1].price.
 */
final class PropsSchema
{
    private const MAX_DEPTH = 8;

    /** @param array<string, array<string, mixed>> $schema name → field spec */
    public function __construct(private readonly array $schema)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function raw(): array
    {
        return $this->schema;
    }

    /** @return array<string, mixed> default props built straight from the schema */
    public function defaults(): array
    {
        /** @var array<string, mixed> $d */
        $d = $this->defaultFor(['type' => 'object', 'shape' => $this->schema]);
        return $d;
    }

    /**
     * @param array<string, mixed> $props
     * @return list<string> errors (empty = valid)
     */
    public function validate(array $props): array
    {
        return $this->validateValue(['type' => 'object', 'shape' => $this->schema], $props, '', 0);
    }

    /** @param array<string, mixed> $props */
    public function getByPath(array $props, string $path): mixed
    {
        $cur = $props;
        foreach ($this->segments($path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /** Field spec governing a dotted path, or null if the path is not in the schema. */
    public function fieldSpecAt(string $path): ?array
    {
        $cur = ['type' => 'object', 'shape' => $this->schema];
        foreach ($this->segments($path) as $seg) {
            $type = $cur['type'] ?? 'string';
            if ($type === 'object') {
                $shape = (array) ($cur['shape'] ?? []);
                if (!array_key_exists((string) $seg, $shape)) {
                    return null;
                }
                $cur = (array) $shape[$seg];
            } elseif ($type === 'array') {
                if (!ctype_digit((string) $seg)) {
                    return null;
                }
                $cur = $this->itemSpec($cur);
            } else {
                return null; // cannot descend into a scalar
            }
        }
        return $cur;
    }

    /** Item field spec for an array path (the element shape), or null. */
    public function itemSpecAt(string $arrayPath): ?array
    {
        $spec = $arrayPath === '' ? ['type' => 'array'] : $this->fieldSpecAt($arrayPath);
        if ($spec === null || ($spec['type'] ?? '') !== 'array') {
            return null;
        }
        return $this->itemSpec($spec);
    }

    /** Default value for an array element (used by arrayinsert with no item given). */
    public function defaultItemAt(string $arrayPath): mixed
    {
        $item = $this->itemSpecAt($arrayPath);
        return $item === null ? null : $this->defaultFor($item);
    }

    /**
     * Coerce a scalar/object value to the field spec: clamp numbers, truncate
     * strings, cast bools, enforce enum/color. Throws on a type that cannot be
     * coerced (object/array shape mismatch).
     * @throws PluginException
     */
    public function coerce(array $spec, mixed $value): mixed
    {
        switch ($spec['type'] ?? 'string') {
            case 'enum':
                $values = (array) ($spec['values'] ?? []);
                if (!in_array($value, $values, true)) {
                    throw new PluginException('value not in enum');
                }
                return $value;
            case 'bool':
                return (bool) $value;
            case 'number':
                if (!is_int($value) && !is_float($value)) {
                    throw new PluginException('expected number');
                }
                $n = $value + 0;
                if (isset($spec['min'])) {
                    $n = max((float) $spec['min'], (float) $n);
                }
                if (isset($spec['max'])) {
                    $n = min((float) $spec['max'], (float) $n);
                }
                // keep integers integral
                return ($n == (int) $n) ? (int) $n : (float) $n;
            case 'string':
            case 'richtext':
            case 'color':
                if (!is_string($value)) {
                    throw new PluginException('expected string');
                }
                $max = (int) ($spec['max'] ?? ($spec['type'] === 'color' ? 32 : 100000));
                if (strlen($value) > $max) {
                    $value = substr($value, 0, $max);
                }
                if (($spec['type'] ?? '') === 'color' && preg_match('/^#?[0-9a-fA-F]{3,8}$|^[a-z]{2,20}$/', $value) !== 1) {
                    throw new PluginException('invalid color');
                }
                return $value;
            case 'object':
                if (!is_array($value)) {
                    throw new PluginException('expected object');
                }
                $errors = $this->validateValue($spec, $value, '', 0);
                if ($errors !== []) {
                    throw new PluginException('object failed shape: ' . implode('; ', $errors));
                }
                return $value;
            case 'array':
                if (!is_array($value) || $value !== array_values($value)) {
                    throw new PluginException('expected list');
                }
                $errors = $this->validateValue($spec, $value, '', 0);
                if ($errors !== []) {
                    throw new PluginException('array failed shape: ' . implode('; ', $errors));
                }
                return $value;
            default:
                throw new PluginException('unknown field type');
        }
    }

    /** @return list<int|string> split a dotted path; numeric segments become ints */
    public function splitPath(string $path): array
    {
        return $this->segments($path);
    }

    /** @return list<int|string> */
    private function segments(string $path): array
    {
        if ($path === '') {
            return [];
        }
        $out = [];
        foreach (explode('.', $path) as $seg) {
            $out[] = ctype_digit($seg) ? (int) $seg : $seg;
        }
        return $out;
    }

    private function itemSpec(array $arraySpec): array
    {
        $of = $arraySpec['of'] ?? 'string';
        if ($of === 'object') {
            return ['type' => 'object', 'shape' => (array) ($arraySpec['shape'] ?? [])];
        }
        return ['type' => (string) $of];
    }

    private function defaultFor(array $spec): mixed
    {
        switch ($spec['type'] ?? 'string') {
            case 'enum':
                $values = (array) ($spec['values'] ?? []);
                return $spec['default'] ?? ($values[0] ?? null);
            case 'bool':
                return (bool) ($spec['default'] ?? false);
            case 'number':
                $n = $spec['default'] ?? $spec['min'] ?? 0;
                return ($n == (int) $n) ? (int) $n : (float) $n;
            case 'color':
                return (string) ($spec['default'] ?? '#000000');
            case 'string':
            case 'richtext':
                return (string) ($spec['default'] ?? '');
            case 'array':
                return is_array($spec['default'] ?? null) ? $spec['default'] : [];
            case 'object':
                $out = [];
                foreach ((array) ($spec['shape'] ?? []) as $name => $sub) {
                    $out[$name] = $this->defaultFor((array) $sub);
                }
                return $out;
            default:
                return null;
        }
    }

    /** @return list<string> */
    private function validateValue(array $spec, mixed $value, string $label, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ["too deep: {$label}"];
        }
        $errors = [];
        switch ($spec['type'] ?? 'string') {
            case 'enum':
                if (!in_array($value, (array) ($spec['values'] ?? []), true)) {
                    $errors[] = "not in enum: {$label}";
                }
                break;
            case 'bool':
                if (!is_bool($value)) {
                    $errors[] = "expected bool: {$label}";
                }
                break;
            case 'number':
                if (!is_int($value) && !is_float($value)) {
                    $errors[] = "expected number: {$label}";
                    break;
                }
                if (isset($spec['min']) && $value < $spec['min']) {
                    $errors[] = "below min: {$label}";
                }
                if (isset($spec['max']) && $value > $spec['max']) {
                    $errors[] = "above max: {$label}";
                }
                break;
            case 'string':
            case 'richtext':
            case 'color':
                if (!is_string($value)) {
                    $errors[] = "expected string: {$label}";
                    break;
                }
                $max = (int) ($spec['max'] ?? ($spec['type'] === 'color' ? 32 : 100000));
                if (strlen($value) > $max) {
                    $errors[] = "too long: {$label}";
                }
                break;
            case 'object':
                if (!is_array($value)) {
                    $errors[] = "expected object: {$label}";
                    break;
                }
                $shape = (array) ($spec['shape'] ?? []);
                foreach ($value as $k => $v) {
                    if (!array_key_exists((string) $k, $shape)) {
                        $errors[] = "unknown field: {$label}{$k}";
                    }
                }
                foreach ($shape as $name => $sub) {
                    $sub = (array) $sub;
                    if (!array_key_exists($name, $value)) {
                        continue; // missing optional → defaulted elsewhere
                    }
                    $errors = array_merge(
                        $errors,
                        $this->validateValue($sub, $value[$name], $label . $name . '.', $depth + 1)
                    );
                }
                break;
            case 'array':
                if (!is_array($value) || $value !== array_values($value)) {
                    $errors[] = "expected list: {$label}";
                    break;
                }
                if (isset($spec['max']) && count($value) > (int) $spec['max']) {
                    $errors[] = "too many items: {$label}";
                    break;
                }
                $item = $this->itemSpec($spec);
                foreach ($value as $i => $v) {
                    $errors = array_merge(
                        $errors,
                        $this->validateValue($item, $v, $label . "[$i].", $depth + 1)
                    );
                }
                break;
            default:
                $errors[] = "unknown schema type: {$label}";
        }
        return $errors;
    }
}
