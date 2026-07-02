<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\Storage\PagesIndex;
use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PagesIndex $pages,
        private readonly AuthService $auth,
        private readonly Config $config,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('dashboard.twig', [
            'pages' => $this->pages->list(),
            'user' => $this->auth->user(),
            'site_root' => $this->config->siteRoot(),
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
