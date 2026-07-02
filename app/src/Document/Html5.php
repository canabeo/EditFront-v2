<?php

declare(strict_types=1);

namespace EditFront\Document;

// NB: alias required — PHP class names are case-insensitive, a plain
// `use Masterminds\HTML5` collides with this class's own name `Html5`
use Masterminds\HTML5 as MastermindsHtml5;

/**
 * Canonical HTML5 parse/serialize. The serializer post-process pins the
 * whitespace around </body></html> — without it Masterminds inflates the
 * tail on every save and the diff history turns into noise (§4.1).
 */
final class Html5
{
    private MastermindsHtml5 $html5;

    public function __construct()
    {
        $this->html5 = new MastermindsHtml5(['disable_html_ns' => true]);
    }

    public function parse(string $html): \DOMDocument
    {
        return $this->html5->loadHTML($html);
    }

    public function serialize(\DOMDocument $doc): string
    {
        $out = $this->html5->saveHTML($doc);
        return preg_replace('~\s*</body>\s*</html>\s*$~', "\n</body>\n</html>\n", $out) ?? $out;
    }

    /**
     * Parse an HTML fragment and import its top-level nodes into $doc.
     * @return list<\DOMNode>
     */
    public function importFragment(\DOMDocument $doc, string $html): array
    {
        $fragment = $this->html5->loadHTMLFragment($html);
        $nodes = [];
        foreach (iterator_to_array($fragment->childNodes) as $child) {
            $nodes[] = $doc->importNode($child, true);
        }
        return $nodes;
    }

    /** Serialize a node's children (innerHTML). */
    public function innerHtml(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->html5->saveHTML($child);
        }
        return $out;
    }
}
