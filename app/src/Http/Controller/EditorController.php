<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Document\Annotator;
use EditFront\Document\DocumentService;
use EditFront\Document\FontFaceRenderer;
use EditFront\Document\Html5;
use EditFront\Font\FontService;
use EditFront\Http\UrlHelper;
use EditFront\I18n\Translator;
use EditFront\Plugin\OrphanResolver;
use EditFront\Plugin\PluginManager;
use EditFront\Security\Csrf;
use EditFront\Storage\StorageException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

final class EditorController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly Html5 $html5,
        private readonly UrlHelper $url,
        private readonly Environment $twig,
        private readonly PluginManager $plugins,
        private readonly OrphanResolver $orphans,
        private readonly Translator $i18n,
        private readonly Csrf $csrf,
        private readonly FontFaceRenderer $fontFaces,
        private readonly FontService $fonts,
    ) {
    }

    /** GET /edit?page=X — editor shell. Opening stamps ids to disk (§4.1). */
    public function edit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = $this->pageParam($request);
        $html = $this->openOr404($request, $page);

        $response->getBody()->write($this->twig->render('editor.twig', [
            'page' => $page,
            'page_sha' => hash('sha256', $html),
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /preview?page=X — annotated page + injected editor runtime for the iframe. */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = $this->pageParam($request);
        $html = $this->openOr404($request, $page);

        $doc = $this->html5->parse($html);

        // classify plugin blocks BEFORE injecting our own scripts (§6.5.1)
        $elements = $this->orphans->classify($doc, $page);

        // CMS-managed @font-face for self-hosted fonts (presets + uploads)
        $this->fontFaces->apply($doc);
        $plugins = $this->plugins->clientManifest(
            fn (string $slug, string $rel): string => $this->url->path('plugin-asset/' . $slug . '/' . $rel)
        );

        // <base> so the page's relative assets resolve as on the live site
        $dir = str_replace('\\', '/', dirname($page));
        $baseHref = $this->url->siteUrl($dir === '.' ? '' : $dir . '/');
        $head = $doc->getElementsByTagName('head')->item(0);
        if ($head !== null) {
            $base = $doc->createElement('base');
            $base->setAttribute('href', $baseHref);
            $base->setAttribute(Annotator::PROTECTED_ATTR, 'true');
            $head->insertBefore($base, $head->firstChild);

            $css = $doc->createElement('link');
            $css->setAttribute('rel', 'stylesheet');
            $css->setAttribute('href', $this->url->asset('preview-inject.css'));
            $css->setAttribute(Annotator::PROTECTED_ATTR, 'true');
            $head->appendChild($css);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $boot = $doc->createElement('script');
            $boot->textContent = 'window.__cmsPreview = ' . json_encode([
                'basePath' => $this->url->path(''),
                'page' => $page,
                'typesUrl' => $this->url->asset('element-types.json'),
                'iconsUrl' => $this->url->asset('icons.svg'),
                'plugins' => $plugins,
                'elements' => $elements,
                // The CSRF token deliberately does NOT travel into the preview:
                // that document is sandboxed and holds the user's own page, so it
                // is the last place a credential should live. Uploads and listings
                // go through the shell (cms:proxy), which holds the token.
                'uploadUrl' => $this->url->path('api/upload'),
                'imagesUrl' => $this->url->path('api/images'),
                // full active-language dict so preview-inject t() needs no extra fetch (§8.3)
                'locale' => $this->i18n->current(),
                'i18n' => $this->i18n->dict($this->i18n->current()),
                // self-hosted font families for the in-preview font picker
                'fonts' => $this->fonts->families(),
                'presetFonts' => $this->fonts->presetFamilies(),
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';
            $body->appendChild($boot);

            $script = $doc->createElement('script');
            $script->setAttribute('src', $this->url->asset('preview-inject.js'));
            $script->setAttribute('defer', 'defer');
            $body->appendChild($script);
        }

        $response->getBody()->write($this->html5->serialize($doc));
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            // the user's own page may pull external fonts/scripts — relax CSP for preview only
            ->withHeader('X-Cms-Relax-Csp', '1');
    }

    private function pageParam(ServerRequestInterface $request): string
    {
        $page = $request->getQueryParams()['page'] ?? '';
        if (!is_string($page) || $page === '') {
            throw new HttpNotFoundException($request, 'page parameter missing');
        }
        return $page;
    }

    private function openOr404(ServerRequestInterface $request, string $page): string
    {
        try {
            return $this->documents->openForEdit($page);
        } catch (StorageException $e) {
            throw new HttpNotFoundException($request, $e->getMessage());
        }
    }
}
