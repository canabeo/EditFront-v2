<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/**
 * Server mirror of the schema-driven form ops (§6.4.1): setprop, arrayinsert,
 * arrayremove, arraymove applied to a props array, with the path validated
 * against the kind's PropsSchema (unknown path / wrong type / out of bounds →
 * reject). Every op has a verified inverse — invert(apply(p)) ≡ p — which is
 * exactly what the fixtures-round-trip gate checks at enable time (§6.4.2) and
 * what the client replays on undo.
 */
final class PropsMutator
{
    public const MODES = ['setprop', 'arrayinsert', 'arrayremove', 'arraymove'];

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $forward
     * @return array<string, mixed> new props
     * @throws PluginException
     */
    public function apply(string $mode, array $props, array $forward, PropsSchema $schema): array
    {
        $path = (string) ($forward['path'] ?? '');

        switch ($mode) {
            case 'setprop':
                $spec = $schema->fieldSpecAt($path);
                if ($spec === null) {
                    throw new PluginException('unknown prop path: ' . $path);
                }
                if (($spec['type'] ?? '') === 'array') {
                    throw new PluginException('setprop cannot target an array: ' . $path);
                }
                $value = $schema->coerce($spec, $forward['value'] ?? null);
                return $this->setLeaf($props, $schema->splitPath($path), $value);

            case 'arrayinsert':
                $item = $schema->itemSpecAt($path);
                if ($item === null) {
                    throw new PluginException('not an array path: ' . $path);
                }
                $raw = array_key_exists('item', $forward) ? $forward['item'] : $schema->defaultItemAt($path);
                $coerced = $schema->coerce($item, $raw);
                $arrSpec = $schema->fieldSpecAt($path) ?? [];
                $max = isset($arrSpec['max']) ? (int) $arrSpec['max'] : null;
                return $this->withArray($props, $schema->splitPath($path), function (array $arr) use ($forward, $coerced, $max): array {
                    if ($max !== null && count($arr) >= $max) {
                        throw new PluginException('array at max');
                    }
                    $index = $this->clampIndex($forward['index'] ?? count($arr), count($arr));
                    array_splice($arr, $index, 0, [$coerced]);
                    return $arr;
                });

            case 'arrayremove':
                if ($schema->itemSpecAt($path) === null) {
                    throw new PluginException('not an array path: ' . $path);
                }
                return $this->withArray($props, $schema->splitPath($path), function (array $arr) use ($forward): array {
                    $index = (int) ($forward['index'] ?? -1);
                    if ($index < 0 || $index >= count($arr)) {
                        throw new PluginException('arrayremove index out of range');
                    }
                    array_splice($arr, $index, 1);
                    return $arr;
                });

            case 'arraymove':
                if ($schema->itemSpecAt($path) === null) {
                    throw new PluginException('not an array path: ' . $path);
                }
                return $this->withArray($props, $schema->splitPath($path), function (array $arr) use ($forward): array {
                    $from = (int) ($forward['from'] ?? -1);
                    $to = (int) ($forward['to'] ?? -1);
                    $n = count($arr);
                    if ($from < 0 || $from >= $n || $to < 0 || $to >= $n) {
                        throw new PluginException('arraymove index out of range');
                    }
                    $item = array_splice($arr, $from, 1);
                    array_splice($arr, $to, 0, $item);
                    return $arr;
                });

            default:
                throw new PluginException('unknown mutate mode: ' . $mode);
        }
    }

    /**
     * The inverse forward payload, computed from the state BEFORE the op.
     * Applying invert() after apply() restores the original props exactly.
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $beforeProps
     * @return array{mode: string, forward: array<string, mixed>}
     */
    public function invert(string $mode, array $forward, array $beforeProps, PropsSchema $schema): array
    {
        $path = (string) ($forward['path'] ?? '');
        switch ($mode) {
            case 'setprop':
                return ['mode' => 'setprop', 'forward' => [
                    'path' => $path,
                    'value' => $schema->getByPath($beforeProps, $path),
                ]];
            case 'arrayinsert':
                $arr = $schema->getByPath($beforeProps, $path);
                $index = $this->clampIndex($forward['index'] ?? (is_array($arr) ? count($arr) : 0), is_array($arr) ? count($arr) : 0);
                return ['mode' => 'arrayremove', 'forward' => ['path' => $path, 'index' => $index]];
            case 'arrayremove':
                $arr = $schema->getByPath($beforeProps, $path);
                $index = (int) ($forward['index'] ?? 0);
                $item = is_array($arr) ? ($arr[$index] ?? null) : null;
                return ['mode' => 'arrayinsert', 'forward' => ['path' => $path, 'index' => $index, 'item' => $item]];
            case 'arraymove':
                return ['mode' => 'arraymove', 'forward' => [
                    'path' => $path,
                    'from' => (int) ($forward['to'] ?? 0),
                    'to' => (int) ($forward['from'] ?? 0),
                ]];
            default:
                throw new PluginException('unknown mutate mode: ' . $mode);
        }
    }

    private function clampIndex(mixed $index, int $len): int
    {
        $i = (int) $index;
        return max(0, min($i, $len));
    }

    /**
     * @param array<string, mixed> $props
     * @param list<int|string> $segs
     * @return array<string, mixed>
     */
    private function setLeaf(array $props, array $segs, mixed $value): array
    {
        if ($segs === []) {
            throw new PluginException('empty path');
        }
        $copy = $props;
        $ref = &$copy;
        for ($i = 0, $n = count($segs); $i < $n - 1; $i++) {
            $seg = $segs[$i];
            if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                throw new PluginException('path does not exist');
            }
            $ref = &$ref[$seg];
        }
        $last = $segs[$n - 1];
        if (!is_array($ref)) {
            throw new PluginException('cannot set leaf on non-array parent');
        }
        $ref[$last] = $value;
        return $copy;
    }

    /**
     * @param array<string, mixed> $props
     * @param list<int|string> $segs
     * @param callable(list<mixed>): list<mixed> $fn
     * @return array<string, mixed>
     */
    private function withArray(array $props, array $segs, callable $fn): array
    {
        $copy = $props;
        $ref = &$copy;
        foreach ($segs as $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                throw new PluginException('array path does not exist');
            }
            $ref = &$ref[$seg];
        }
        if (!is_array($ref) || $ref !== array_values($ref)) {
            throw new PluginException('target is not a list');
        }
        $ref = $fn($ref);
        return $copy;
    }
}
