<?php

declare(strict_types=1);

namespace EditFront\Document;

/**
 * ANNOTATE_ONLY mode (§4.1): stamp data-cms-id with a string-level pass that
 * preserves the original formatting byte-for-byte except the inserted
 * attributes — no full recanonicalization at open time.
 *
 * Known limitation (documented in the spec): data-cms-protected here skips
 * only the node itself, not its subtree; full canonicalization still happens
 * on the first real save.
 */
final class TagAnnotatorLite
{
    private const RAW_TEXT_TAGS = ['script', 'style', 'textarea', 'title'];

    public function __construct(private readonly Annotator $annotator)
    {
    }

    /** @return array{0: string, 1: bool} [annotated html, changed] */
    public function annotate(string $html): array
    {
        $len = strlen($html);
        $out = '';
        $i = 0;
        $changed = false;

        while ($i < $len) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                $out .= substr($html, $i);
                break;
            }
            $out .= substr($html, $i, $lt - $i);

            // comments copied verbatim
            if (substr($html, $lt, 4) === '<!--') {
                $end = strpos($html, '-->', $lt + 4);
                $end = $end === false ? $len : $end + 3;
                $out .= substr($html, $lt, $end - $lt);
                $i = $end;
                continue;
            }

            // start tag?
            if (preg_match(
                '~^<([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)(/?)>~',
                substr($html, $lt, 4096),
                $m
            ) !== 1) {
                $out .= '<';
                $i = $lt + 1;
                continue;
            }

            $tag = strtolower($m[1]);
            $attrs = $m[2];
            $full = $m[0];

            $eligible = !in_array($tag, Annotator::PROTECTED_TAGS, true)
                && stripos($attrs, Annotator::ID_ATTR . '=') === false
                && stripos($attrs, Annotator::PROTECTED_ATTR) === false;

            if ($eligible) {
                $full = '<' . $m[1] . ' ' . Annotator::ID_ATTR . '="' . $this->annotator->generateId() . '"'
                    . $m[2] . $m[3] . '>';
                $changed = true;
            }

            $out .= $full;
            $i = $lt + strlen($m[0]);

            // raw-text elements: copy content verbatim until the closing tag
            if (in_array($tag, self::RAW_TEXT_TAGS, true) && $m[3] !== '/') {
                if (preg_match('~</' . $tag . '\b[^>]*>~i', $html, $mm, PREG_OFFSET_CAPTURE, $i) === 1) {
                    $end = $mm[0][1] + strlen($mm[0][0]);
                } else {
                    $end = $len;
                }
                $out .= substr($html, $i, $end - $i);
                $i = $end;
            }
        }

        return [$out, $changed];
    }
}
