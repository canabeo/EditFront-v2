<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Bug 8: the preview iframe used to be same-origin, unsandboxed, under a CSP
 * that allowed everything. It renders the USER's page, which legitimately loads
 * third-party scripts (analytics, chat widgets, pixels). Same-origin, any of
 * those ran inside the admin: it could read data-cms-csrf off the shell's DOM
 * and drive the admin API with the logged-in admin's session. The trigger was
 * not exotic — one compromised vendor tag was enough, every time the admin
 * opened the editor.
 *
 * These are source-level guards. The behaviour they protect lives in the
 * browser, so a unit test cannot exercise it; what it CAN do is make the
 * invariants impossible to undo silently during a later refactor.
 */
final class PreviewSandboxTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_preview_iframe_is_sandboxed_without_same_origin(): void
    {
        $twig = $this->read('app/templates/editor.twig');

        $this->assertMatchesRegularExpression(
            '/<iframe[^>]*id="ef-preview"[^>]*sandbox="[^"]*"/s',
            $twig,
            'the preview iframe must carry a sandbox attribute',
        );

        preg_match('/<iframe[^>]*id="ef-preview"[^>]*sandbox="([^"]*)"/s', $twig, $m);
        $tokens = preg_split('/\s+/', trim($m[1] ?? ''));

        // the whole point: an opaque origin
        $this->assertNotContains(
            'allow-same-origin',
            $tokens,
            'allow-same-origin would hand the admin origin back to the page being edited',
        );
        // the editor runtime needs these two, and nothing more
        $this->assertContains('allow-scripts', $tokens);
        $this->assertContains('allow-modals', $tokens);
    }

    public function test_the_csrf_token_never_reaches_the_previewed_document(): void
    {
        $controller = $this->read('app/src/Http/Controller/EditorController.php');

        // the preview payload is built inline; the token must not be one of its keys
        $this->assertDoesNotMatchRegularExpression(
            "/'csrf'\s*=>/",
            $controller,
            'the CSRF token must stay in the shell — the preview holds untrusted content',
        );
    }

    public function test_the_preview_runtime_calls_the_api_through_the_shell(): void
    {
        $js = $this->read('assets/preview-inject.js');

        // A sandboxed document cannot reach the API itself: cross-origin, no
        // cookies. Direct calls would fail at runtime, so they must not return.
        $this->assertStringNotContainsString('CFG.csrf', $js);
        $this->assertStringNotContainsString("fetch(CFG.uploadUrl", $js);
        $this->assertStringNotContainsString("fetch(CFG.imagesUrl", $js);
        $this->assertStringNotContainsString("fetch(CFG.typesUrl", $js);

        // …and the proxy that replaced them is wired to the shell
        $this->assertStringContainsString("proxy('upload'", $js);
        $this->assertStringContainsString("proxy('images')", $js);
        $this->assertStringContainsString("proxy('types')", $js);
        $this->assertStringContainsString("type: 'cms:proxy'", $js);
    }

    public function test_both_sides_authenticate_messages_by_source_not_origin(): void
    {
        $shell = $this->read('assets/editor-shell.js');
        $preview = $this->read('assets/preview-inject.js');

        // Under the sandbox the preview's origin is the string "null", so an
        // origin comparison can no longer tell friend from foe. The frame
        // identity can: only the frame we created is e.source.
        $this->assertStringContainsString('e.source !== iframe.contentWindow', $shell);
        $this->assertStringContainsString('e.source !== window.parent', $preview);

        $this->assertStringContainsString("handleProxy(d)", $shell);
    }
}
