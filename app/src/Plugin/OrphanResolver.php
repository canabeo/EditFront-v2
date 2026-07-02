<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use EditFront\Document\Annotator;

/**
 * Classifies every data-cms-block node at open (§6.5.1 — the main Free case:
 * editing an EXISTING site that may carry foreign blocks). The invariant: an
 * orphan/incompatible plugin node NEVER crashes the open and NEVER stays
 * silent — it is either recovered via serialize() or marked read-only with a
 * clear reason. The rest of the page stays fully editable.
 *
 * Status: ok (sidecar props) · restored (props recovered from DOM) ·
 * unknown-kind (plugin not installed) · invalid-import / invalid-sidecar
 * (props don't validate) · migrate-fail.
 */
final class OrphanResolver
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PropsStore $props,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> id → {kind, slug?, editable,
     *   status, label, version?, props?}
     */
    public function classify(\DOMDocument $doc, string $page): array
    {
        $this->plugins->boot();
        $types = $this->plugins->elementTypes();
        $sidecar = $this->props->load($page);

        $out = [];
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@data-cms-block]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $id = $node->getAttribute(Annotator::ID_ATTR);
            if ($id === '') {
                continue; // un-stamped block can't be addressed; skip (ensureAnnotated stamps it)
            }
            $kindName = $node->getAttribute('data-cms-block');
            $rk = $types->get($kindName);

            if ($rk === null) {
                $out[$id] = [
                    'kind' => $kindName,
                    'editable' => false,
                    'status' => 'unknown-kind',
                    'label' => $kindName,
                ];
                continue;
            }

            $sc = $sidecar[$id] ?? null;
            $props = is_array($sc) && is_array($sc['props'] ?? null) ? $sc['props'] : null;
            $fromSidecar = $props !== null;

            if ($props === null) {
                try {
                    $props = $rk->block()->serialize($node);
                } catch (\Throwable) {
                    $out[$id] = $this->degrade($kindName, $rk, 'invalid-import');
                    continue;
                }
            }

            $nodeV = (int) ($node->getAttribute('data-cms-v') ?: '1');
            if ($nodeV < $rk->version()) {
                try {
                    $props = $rk->block()->migrate($props, $nodeV, $rk->version());
                } catch (\Throwable) {
                    $out[$id] = $this->degrade($kindName, $rk, 'migrate-fail');
                    continue;
                }
            }

            if ($rk->propsSchema()->validate($props) !== []) {
                $out[$id] = $this->degrade($kindName, $rk, $fromSidecar ? 'invalid-sidecar' : 'invalid-import');
                continue;
            }

            $out[$id] = [
                'kind' => $kindName,
                'slug' => $rk->slug(),
                'editable' => true,
                'status' => $fromSidecar ? 'ok' : 'restored',
                'version' => $rk->version(),
                'label' => $rk->label(),
                'props' => $props,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function degrade(string $kindName, RegisteredKind $rk, string $status): array
    {
        return [
            'kind' => $kindName,
            'slug' => $rk->slug(),
            'editable' => false,
            'status' => $status,
            'label' => $rk->label(),
        ];
    }
}
