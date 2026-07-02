<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\SanitizerCss;
use PHPUnit\Framework\TestCase;

final class SanitizerCssTest extends TestCase
{
    private SanitizerCss $css;

    protected function setUp(): void
    {
        $this->css = new SanitizerCss();
    }

    public function test_prop_validation(): void
    {
        $this->assertTrue($this->css->validProp('font-size'));
        $this->assertTrue($this->css->validProp('color'));
        $this->assertFalse($this->css->validProp('-moz-binding'));
        $this->assertFalse($this->css->validProp('Color'));
        $this->assertFalse($this->css->validProp('font size'));
        $this->assertFalse($this->css->validProp(''));
    }

    public function test_accepts_normal_values(): void
    {
        foreach (['1.25em', 'center', '#fff', 'rgba(0, 0, 0, 0.5)', 'url(/img/x.png)', 'url(https://x/y.png)', '2px solid red'] as $v) {
            $this->assertSame($v, $this->css->value($v), "should accept: $v");
        }
        $this->assertSame('', $this->css->value('   '), 'blank = remove prop');
    }

    public function test_rejects_injection(): void
    {
        foreach ([
            'expression(alert(1))',
            'url(javascript:alert(1))',
            "url('javascript:alert(1)')",
            'red; position: fixed',
            'x</style><script>1</script>',
            "behavior:url('x.htc')",
            '@import "x"',
            "a{b}",
        ] as $v) {
            $this->assertNull($this->css->value($v), "should reject: $v");
        }
    }
}
