<?php

declare(strict_types=1);

namespace EditFront\Reviews;

/**
 * Builds a review card (`.rev`) as a detached \DOMElement, ready
 * to be importNode()'d into a site page by ReviewPublisher.
 *
 * Two skins, selected by $mode (the container's marker value):
 *   - 'page'  → the /reviews list card: a <span class="badge"> with the country
 *               above the (always five) stars, then the text, then who.
 *   - 'home'  → the homepage carousel card (#revGrid): a decorative quote mark
 *               instead of a badge; the country is carried in the meta line.
 *
 * Reviews are TEXT ONLY. The body is written via DOM text nodes (never raw
 * HTML), so a hostile submission cannot inject markup — newlines become <br>.
 * Stars are always five (product decision — "под страной всегда 5 звёздочек").
 */
final class ReviewRenderer
{
    private const STARS = '★★★★★';

    /** Homepage cards truncate long reviews so a big one can't blow out the row. */
    private const HOME_MAX_CHARS = 220;

    /**
     * @param array<string,mixed> $item  a stored review item
     * @param string              $mode  'page' | 'home' (anything else ⇒ 'page')
     */
    public function renderCard(array $item, string $mode): \DOMElement
    {
        $mode = $mode === 'home' ? 'home' : 'page';

        $doc = new \DOMDocument('1.0', 'UTF-8');

        $name    = $this->str($item, 'name');
        $country = $this->str($item, 'country');
        $year    = $this->str($item, 'year');
        $text    = $this->str($item, 'text');

        // homepage carousel: cap length (word-boundary) so a long review does
        // not make its card tower over the others / overflow on mobile.
        if ($mode === 'home') {
            $text = $this->truncate($text, self::HOME_MAX_CHARS);
        }

        $rev = $doc->createElement('div');
        $rev->setAttribute('class', 'rev');

        if ($mode === 'home') {
            $mark = $doc->createElement('span');
            $mark->setAttribute('class', 'mark');
            $mark->appendChild($doc->createTextNode("\u{201C}")); // “ (left double quote)
            $rev->appendChild($mark);
        } elseif ($country !== '') {
            $badge = $doc->createElement('span');
            $badge->setAttribute('class', 'badge');
            $badge->appendChild($doc->createTextNode($country));
            $rev->appendChild($badge);
        }

        // stars — always five
        $st = $doc->createElement('span');
        $st->setAttribute('class', 'st');
        if ($mode === 'page') {
            $st->setAttribute('style', 'color:#FFB100;letter-spacing:2px');
        }
        $st->appendChild($doc->createTextNode(self::STARS));
        $rev->appendChild($st);

        // body text (plain, newline-aware)
        $rev->appendChild($this->buildParagraph($doc, $text));

        // who: avatar initial + name + meta
        $meta = $mode === 'home'
            ? $this->joinMeta($country, $year)
            : $year; // page card already shows the country in the badge

        $rev->appendChild($this->buildWho($doc, $name, $meta));

        return $rev;
    }

    /** A <p> whose textContent is $text, with newlines rendered as <br>. */
    private function buildParagraph(\DOMDocument $doc, string $text): \DOMElement
    {
        $p = $doc->createElement('p');
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $p->appendChild($doc->createElement('br'));
            }
            if ($line !== '') {
                $p->appendChild($doc->createTextNode($line));
            }
        }
        return $p;
    }

    /**
     * <div class="who"><span class="av">{initial}</span>
     *   <span><b>{name}</b>[<span>{meta}</span>]</span></div>
     */
    private function buildWho(\DOMDocument $doc, string $name, string $meta): \DOMElement
    {
        $who = $doc->createElement('div');
        $who->setAttribute('class', 'who');

        $av = $doc->createElement('span');
        $av->setAttribute('class', 'av');
        $av->appendChild($doc->createTextNode($this->initial($name)));
        $who->appendChild($av);

        $col = $doc->createElement('span');
        $b = $doc->createElement('b');
        $b->appendChild($doc->createTextNode($name));
        $col->appendChild($b);

        if ($meta !== '') {
            $metaSpan = $doc->createElement('span');
            $metaSpan->appendChild($doc->createTextNode($meta));
            $col->appendChild($metaSpan);
        }
        $who->appendChild($col);

        return $who;
    }

    /**
     * Truncate to ~$max chars on a word boundary, appending an ellipsis. Newlines
     * are collapsed to spaces first (a truncated card reads as one block).
     */
    private function truncate(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > $max - 30) {
            $cut = mb_substr($cut, 0, $sp);
        }
        return rtrim($cut, " ,.;:—-") . '…';
    }

    /** Uppercase first letter of the name; '·' fallback for an empty name. */
    private function initial(string $name): string
    {
        $first = mb_substr($name, 0, 1, 'UTF-8');
        return $first !== '' ? mb_strtoupper($first, 'UTF-8') : "\u{00B7}";
    }

    /** "Country, Year" — skipping whichever part is blank. */
    private function joinMeta(string $country, string $year): string
    {
        $parts = array_values(array_filter([$country, $year], static fn (string $s): bool => $s !== ''));
        return implode(', ', $parts);
    }

    /** @param array<string,mixed> $item */
    private function str(array $item, string $key): string
    {
        $v = $item[$key] ?? '';
        return is_string($v) ? $v : '';
    }
}
