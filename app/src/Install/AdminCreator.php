<?php

declare(strict_types=1);

namespace EditFront\Install;

use EditFront\Auth\AdminStore;
use EditFront\Support\Config;

/**
 * Install-wizard step 3 (§9 C5): create the single admin in storage/admin.json
 * (bcrypt cost 12), auto-generate APP_SECRET, optionally flip ANNOTATE_ONLY,
 * and take an install lock via flock so the wizard can never run twice or race
 * itself. Throws InstallException (with an HTTP status) on bad input or when
 * the CMS is already installed.
 */
final class AdminCreator
{
    private const USERNAME_RE = '/^[A-Za-z0-9._@-]{1,64}$/';
    private const MIN_PASSWORD = 8;

    public function __construct(
        private readonly Config $config,
        private readonly AdminStore $admins,
        private readonly EnvFile $env,
    ) {
    }

    public function isInstalled(): bool
    {
        return $this->admins->exists();
    }

    /**
     * @param array{annotate_only?: bool} $options
     * @return array{secret_persisted: string, env_writable: bool}
     */
    public function install(string $username, string $password, array $options = []): array
    {
        $username = trim($username);
        if (preg_match(self::USERNAME_RE, $username) !== 1) {
            throw new InstallException('invalid username (letters, digits, . _ @ -, up to 64)', 422);
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InstallException('password must be at least ' . self::MIN_PASSWORD . ' characters', 422);
        }

        $lock = $this->acquireLock();
        try {
            if ($this->isInstalled()) {
                throw new InstallException('already installed', 410);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $this->admins->create($username, $hash);

            $envWritable = $this->env->isWritable();
            $secretWhere = $this->persistSecret($envWritable);

            if (!empty($options['annotate_only'])) {
                $this->env->set('ANNOTATE_ONLY', 'true');
            }

            return ['secret_persisted' => $secretWhere, 'env_writable' => $envWritable];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** Generate APP_SECRET; persist to .env when writable, else a storage file. */
    private function persistSecret(bool $envWritable): string
    {
        if ($this->env->get('APP_SECRET') !== null) {
            return 'env';
        }
        $secret = bin2hex(random_bytes(32));
        if ($envWritable && $this->env->set('APP_SECRET', $secret)) {
            return 'env';
        }
        // fallback: storage/ is always writable (2770) — keep the secret somewhere stable
        $file = $this->config->storageDir() . '/app-secret';
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $secret) !== false && @rename($tmp, $file)) {
            @chmod($file, 0600);
            return 'storage';
        }
        @unlink($tmp);
        return 'none';
    }

    /** @return resource */
    private function acquireLock()
    {
        $dir = $this->config->storageDir() . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $fh = @fopen($dir . '/install.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            if (is_resource($fh)) {
                @fclose($fh);
            }
            throw new InstallException('install already in progress', 409);
        }
        return $fh;
    }

    /** @param resource $fh */
    private function releaseLock($fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}
