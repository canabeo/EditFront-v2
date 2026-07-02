<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\KindCtxFactory;
use EditFront\Plugin\RegisteredKind;
use PHPUnit\Framework\TestCase;

/**
 * §6.3 render↔serialize exact-inverse for the adopted before/after pair: both
 * images (src + alt) and both captions survive render → serialize unchanged,
 * which is what lets an existing pair be adopted straight from the DOM.
 */
final class AdoptBeforeAfterRoundTripTest extends TestCase
{
    private \EditFront\Plugin\BlockKind $kind;
    private \EditFront\Plugin\KindCtx $ctx;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/plugins/adopt-before-after/src/AdoptBeforeAfter.php';
        $class = 'EditFront\\Plugins\\AdoptBeforeAfter\\AdoptBeforeAfter';
        $this->kind = new $class();
        $rk = new RegisteredKind('adopt-before-after', 'core', ['kind' => 'adopt-before-after', 'props_schema' => []], $this->kind);
        $this->ctx = (new KindCtxFactory(ef2_test_config()))->make($rk);
    }

    private function roundTrip(array $props): array
    {
        $html = '<div data-cms-block="adopt-before-after">' . $this->kind->render($props, '', $this->ctx) . '</div>';
        $doc = ef2_doc($html);
        $node = $doc->getElementsByTagName('body')->item(0)->firstElementChild;
        return $this->kind->serialize($node);
    }

    public function test_both_sides_round_trip(): void
    {
        $props = [
            'beforeSrc' => 'a.jpg', 'beforeAlt' => 'Kitchen before', 'beforeLabel' => 'Before',
            'afterSrc' => 'b.jpg', 'afterAlt' => 'Kitchen after', 'afterLabel' => 'After',
        ];
        $this->assertSame($props, $this->roundTrip($props));
    }

    public function test_empty_src_round_trips(): void
    {
        $out = $this->roundTrip([
            'beforeSrc' => '', 'beforeAlt' => '', 'beforeLabel' => 'До',
            'afterSrc' => 'x.jpg', 'afterAlt' => '', 'afterLabel' => 'После',
        ]);
        $this->assertSame('', $out['beforeSrc']);
        $this->assertSame('До', $out['beforeLabel']);
        $this->assertSame('x.jpg', $out['afterSrc']);
        $this->assertSame('После', $out['afterLabel']);
    }
}
