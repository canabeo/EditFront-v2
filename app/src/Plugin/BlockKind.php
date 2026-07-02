<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/**
 * PHP half of an element type (§6.3). Pure functions — no network, no
 * filesystem. The core runs its own sanitizer ON TOP of render()'s output, so
 * a buggy kind can never punch through the core whitelist (§6.7).
 *
 * Round-trip invariant (§6.3): serialize(parse(render(props))) ≈ props — this
 * is what lets an orphan plugin node (sidecar lost / imported HTML) be
 * recovered straight from the DOM (§6.5.1).
 */
interface BlockKind
{
    /** Kind name → data-cms-block="<kind>". Must match a manifest "kind". */
    public function kind(): string;

    /** Current major version of the kind (node carries data-cms-v). */
    public function version(): int;

    /**
     * props + canonical innerHTML → the node's inner HTML. The wrapping
     * <div data-cms-block> is managed by the core, not by render().
     * @param array<string, mixed> $props
     */
    public function render(array $props, string $innerHtml, KindCtx $ctx): string;

    /**
     * Inverse of render: pull structured props back out of a DOM node. Used to
     * recover props when the sidecar is missing/imported (§6.5.1).
     * @return array<string, mixed>
     */
    public function serialize(\DOMElement $node): array;

    /** Sanitize the kind's OWN output (core sanitize still runs on top). */
    public function sanitize(string $html): string;

    /**
     * Validate props against the kind's schema.
     * @param array<string, mixed> $props
     * @return list<string> errors (empty = ok)
     */
    public function validate(array $props): array;

    /**
     * Migrate props between versions of the kind. Failure → read-only degrade
     * (the caller never lets a throw take down the page, §6.6).
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public function migrate(array $props, int $fromV, int $toV): array;
}
