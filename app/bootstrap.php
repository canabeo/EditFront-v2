<?php

declare(strict_types=1);

/**
 * Application bootstrap: container (autowiring), routes, middleware.
 *
 * DI invariant (0.2.7): our own classes are NEVER built by manual factories —
 * pure autowiring only, so a constructor change can never drift from a
 * hand-written factory. The only explicit definitions below are for
 * third-party classes (Twig, Monolog), the Config value object and the
 * OperationRegistry op LIST (registration, not construction — the op objects
 * themselves are autowired). ContainerRoutesTest guards every route in CI.
 *
 * Plugin ops (§6) join the SAME OperationRegistry via PluginManager::boot() —
 * one save pipeline, one parity test, undo/redo for free.
 */

use DI\ContainerBuilder;
use EditFront\Http\Controller\AccountController;
use EditFront\Http\Controller\ApiController;
use EditFront\Http\Controller\BackupController;
use EditFront\Http\Controller\DashboardController;
use EditFront\Http\Controller\DraftController;
use EditFront\Http\Controller\EditorController;
use EditFront\Http\Controller\FontsController;
use EditFront\Http\Controller\InstallController;
use EditFront\Http\Controller\LoginController;
use EditFront\Http\Controller\NewsController;
use EditFront\Http\Controller\ReviewController;
use EditFront\Http\Controller\PageController;
use EditFront\Http\Controller\PluginAdminController;
use EditFront\Http\Controller\PluginAssetController;
use EditFront\Http\Controller\SeoController;
use EditFront\Http\Controller\SitemapController;
use EditFront\Http\Controller\TranslationsController;
use EditFront\Http\Controller\UploadController;
use EditFront\Http\ErrorHandler;
use EditFront\Http\Middleware\AuthMiddleware;
use EditFront\Http\Middleware\CsrfMiddleware;
use EditFront\Http\Middleware\LocaleMiddleware;
use EditFront\Http\Middleware\SecurityHeadersMiddleware;
use EditFront\Http\Middleware\SessionMiddleware;
use EditFront\Http\UrlHelper;
use EditFront\I18n\Translator;
use EditFront\Operation\OperationRegistry;
use EditFront\Operation\Ops\AttrRemoveOp;
use EditFront\Operation\Ops\AttrSetOp;
use EditFront\Operation\Ops\NodeDeleteOp;
use EditFront\Operation\Ops\NodeDuplicateOp;
use EditFront\Operation\Ops\NodeInsertOp;
use EditFront\Operation\Ops\NodeMoveOp;
use EditFront\Operation\Ops\NodeReplaceOp;
use EditFront\Operation\Ops\NodeRestoreOp;
use EditFront\Operation\Ops\StyleSetOp;
use EditFront\Operation\Ops\StyleStateOp;
use EditFront\Operation\Ops\TextSetOp;
use EditFront\Plugin\PluginManager;
use EditFront\Security\Csrf;
use EditFront\Support\Config;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->addDefinitions([

    // NB: DI factories must NOT be static closures — PHP-DI binds them to the container
    Config::class => fn (): Config => new Config(require __DIR__ . '/config/config.php'),

    LoggerInterface::class => function (Config $config): LoggerInterface {
        $dir = $config->storageDir() . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $handler = new RotatingFileHandler($dir . '/app.log', 14, Level::Debug);
        $handler->setFormatter(new JsonFormatter());
        $logger = new Logger('app');
        $logger->pushHandler($handler);
        return $logger;
    },

    Environment::class => function (Config $config, UrlHelper $url, Csrf $csrf, Translator $i18n): Environment {
        $loader = new FilesystemLoader($config->cmsDir() . '/app/templates');
        $twig = new Environment($loader, [
            'cache' => $config->debug() ? false : $config->storageDir() . '/cache/twig',
            'strict_variables' => true,
        ]);
        $twig->addGlobal('base_path', $config->basePath());
        $twig->addFunction(new TwigFunction('url', fn (string $p = ''): string => $url->path($p)));
        $twig->addFunction(new TwigFunction('asset', fn (string $p): string => $url->asset($p)));
        $twig->addFunction(new TwigFunction('csrf_token', fn (): string => $csrf->token()));
        // i18n (§8.3): same t() signature as the client; the locale is set per
        // request by LocaleMiddleware before any template renders.
        $twig->addFunction(new TwigFunction(
            't',
            fn (string $key, array $params = [], ?string $fallback = null): string
                => $i18n->t($key, $params !== [] ? $params : null, $fallback)
        ));
        $twig->addFunction(new TwigFunction('locale', fn (): string => $i18n->current()));
        $twig->addFunction(new TwigFunction('i18n_json', fn (): string => $i18n->dictJson($i18n->current())));
        $twig->addFunction(new TwigFunction('ui_langs', fn (): array => $i18n->supportedLanguages()));
        return $twig;
    },

    // Registration list, not construction: each op spec is autowired by the
    // container; plugin ops join the same registry via PluginManager (§6).
    OperationRegistry::class => function (ContainerInterface $c): OperationRegistry {
        $registry = new OperationRegistry();
        foreach ([
            TextSetOp::class,
            AttrSetOp::class,
            AttrRemoveOp::class,
            StyleSetOp::class,
            StyleStateOp::class,
            NodeInsertOp::class,
            NodeDeleteOp::class,
            NodeMoveOp::class,
            NodeDuplicateOp::class,
            NodeRestoreOp::class,
            NodeReplaceOp::class,
        ] as $opClass) {
            $registry->register($c->get($opClass));
        }
        foreach ($c->get(PluginManager::class)->operationSpecs() as $pluginOp) {
            $registry->register($pluginOp);
        }
        return $registry;
    },
]);

$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath($container->get(Config::class)->basePath());

// --- Routes ---------------------------------------------------------------
$app->get('/login', [LoginController::class, 'form'])->setName('login');
$app->post('/login', [LoginController::class, 'submit']);
$app->get('/install', [InstallController::class, 'index']);
$app->post('/install', [InstallController::class, 'submit']);
$app->get('/sitemap.xml', [SitemapController::class, 'serve']);
$app->post('/api/reviews/submit', [ReviewController::class, 'submit']);

// NB: route-group callback must not be static either — Slim binds it to the container
$app->group('', function (RouteCollectorProxy $group): void {
    $group->get('/', [DashboardController::class, 'index'])->setName('dashboard');
    $group->post('/logout', [LoginController::class, 'logout']);
    $group->get('/edit', [EditorController::class, 'edit'])->setName('edit');
    $group->get('/preview', [EditorController::class, 'preview']);
    $group->get('/plugin-asset/{slug}/{path:.+}', [PluginAssetController::class, 'serve']);
    $group->post('/api/save', [ApiController::class, 'save']);
    $group->get('/api/op-schema', [ApiController::class, 'opSchema']);
    $group->post('/api/pages/create', [PageController::class, 'create']);
    $group->post('/api/pages/duplicate', [PageController::class, 'duplicate']);
    $group->post('/api/pages/delete', [PageController::class, 'delete']);
    $group->post('/api/upload', [UploadController::class, 'upload']);
    $group->get('/api/images', [UploadController::class, 'images']);
    $group->get('/api/backups', [BackupController::class, 'list']);
    $group->post('/api/restore', [BackupController::class, 'restorePage']);
    $group->get('/api/seo', [SeoController::class, 'load']);
    $group->post('/api/seo', [SeoController::class, 'save']);
    $group->get('/settings/account', [AccountController::class, 'page'])->setName('account');
    $group->post('/settings/account', [AccountController::class, 'changePassword']);
    $group->get('/settings/fonts', [FontsController::class, 'page'])->setName('fonts');
    $group->post('/settings/fonts', [FontsController::class, 'upload']);
    $group->post('/settings/fonts/delete', [FontsController::class, 'delete']);
    $group->get('/settings/news', [NewsController::class, 'page'])->setName('news');
    $group->get('/api/news', [NewsController::class, 'list']);
    $group->post('/api/news', [NewsController::class, 'save']);
    $group->post('/api/news/delete', [NewsController::class, 'delete']);
    $group->get('/settings/reviews', [ReviewController::class, 'page'])->setName('reviews');
    $group->get('/api/reviews', [ReviewController::class, 'list']);
    $group->post('/api/reviews', [ReviewController::class, 'save']);
    $group->post('/api/reviews/approve', [ReviewController::class, 'approve']);
    $group->post('/api/reviews/reject', [ReviewController::class, 'reject']);
    $group->post('/api/reviews/unpublish', [ReviewController::class, 'unpublish']);
    $group->post('/api/reviews/delete', [ReviewController::class, 'delete']);
    $group->get('/settings/translations', [TranslationsController::class, 'page'])->setName('translations');
    $group->post('/settings/translations', [TranslationsController::class, 'save']);
    $group->post('/settings/translations/add-language', [TranslationsController::class, 'addLanguage']);
    $group->get('/settings/plugins', [PluginAdminController::class, 'page'])->setName('plugins');
    $group->post('/api/plugins/toggle', [PluginAdminController::class, 'toggle']);
    $group->post('/api/plugins/uninstall', [PluginAdminController::class, 'uninstall']);
    $group->post('/api/plugins/install', [PluginAdminController::class, 'install']);
    $group->get('/api/draft', [DraftController::class, 'get']);
    $group->post('/api/draft', [DraftController::class, 'save']);
    $group->post('/api/draft/delete', [DraftController::class, 'delete']);
    $group->post('/api/undo-payload', [DraftController::class, 'payloadSave']);
    $group->get('/api/undo-payload', [DraftController::class, 'payloadGet']);
})->add(AuthMiddleware::class);

// --- Middleware (LIFO: the LAST added executes FIRST) ----------------------
// Execution order: SecurityHeaders → Error → Locale → Session → BodyParsing → Csrf → Routing
$app->addRoutingMiddleware();
$app->add(CsrfMiddleware::class);
$app->addBodyParsingMiddleware();
$app->add(SessionMiddleware::class);
// resolves the UI language + handles ?lang→cookie+302 before any handler renders
$app->add(LocaleMiddleware::class);

$errorMiddleware = $app->addErrorMiddleware(
    $container->get(Config::class)->debug(),
    true,
    true,
    $container->get(LoggerInterface::class)
);
$errorMiddleware->setDefaultErrorHandler($container->get(ErrorHandler::class));

// Registered LAST → runs FIRST: headers go onto every response, errors included
$app->add(SecurityHeadersMiddleware::class);

return $app;
