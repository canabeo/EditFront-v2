<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\KindCtxFactory;
use EditFront\Plugin\RegisteredKind;
use PHPUnit\Framework\TestCase;

/**
 * §6.3 render↔serialize exact-inverse for the adopted gallery: the data-images
 * JSON attribute (the site lightbox's source of truth) and the cover/title must
 * survive render → serialize unchanged — that is what lets an existing card be
 * adopted straight from the DOM and re-rendered without drift.
 */
final class AdoptGalleryRoundTripTest extends TestCase
{
    private \EditFront\Plugin\BlockKind $kind;
    private \EditFront\Plugin\KindCtx $ctx;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/plugins/adopt-gallery/src/AdoptGallery.php';
        $class = 'EditFront\\Plugins\\AdoptGallery\\AdoptGallery';
        $this->kind = new $class();
        $rk = new RegisteredKind('adopt-gallery', 'core', ['kind' => 'adopt-gallery', 'props_schema' => []], $this->kind);
        $this->ctx = (new KindCtxFactory(ef2_test_config()))->make($rk);
    }

    private function roundTrip(array $props): array
    {
        $html = '<div data-cms-block="adopt-gallery">' . $this->kind->render($props, '', $this->ctx) . '</div>';
        $doc = ef2_doc($html);
        $node = $doc->getElementsByTagName('body')->item(0)->firstElementChild;
        return $this->kind->serialize($node);
    }

    public function test_title_cover_and_images_round_trip(): void
    {
        $props = ['title' => 'Hi-Tech', 'cover' => 'k/c.jpg', 'images' => [
            ['src' => 'k/a.jpg'], ['src' => 'k/b.jpg'], ['src' => 'k/c.jpg'],
        ]];
        $out = $this->roundTrip($props);
        $this->assertSame('Hi-Tech', $out['title']);
        $this->assertSame('k/c.jpg', $out['cover']);
        $this->assertSame([['src' => 'k/a.jpg'], ['src' => 'k/b.jpg'], ['src' => 'k/c.jpg']], $out['images']);
    }

    public function test_empty_cover_defaults_to_first_image_and_round_trips(): void
    {
        $props = ['title' => 'G', 'cover' => '', 'images' => [['src' => 'one.jpg'], ['src' => 'two.jpg']]];
        $out = $this->roundTrip($props);
        // render falls back to the first image for the cover <img>; serialize then
        // recovers that as the cover — a stable fixed point through the round-trip.
        $this->assertSame('one.jpg', $out['cover']);
        $this->assertCount(2, $out['images']);
    }

    public function test_empty_gallery_round_trips(): void
    {
        $out = $this->roundTrip(['title' => 'Empty', 'cover' => '', 'images' => []]);
        $this->assertSame('Empty', $out['title']);
        $this->assertSame('', $out['cover']);
        $this->assertSame([], $out['images']);
    }

    public function test_special_chars_in_title_survive(): void
    {
        $out = $this->roundTrip(['title' => 'A & B "q" <tag>', 'cover' => 'x.jpg', 'images' => [['src' => 'x.jpg']]]);
        $this->assertSame('A & B "q" <tag>', $out['title']);
    }
}
