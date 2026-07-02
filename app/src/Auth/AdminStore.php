<?php

declare(strict_types=1);

namespace EditFront\Auth;

use EditFront\Support\Config;

/**
 * Single-admin credentials in storage/admin.json (created by the install
 * wizard in C5; until then bootstrapped from .env ADMIN_USERNAME +
 * ADMIN_PASSWORD_HASH). Verification is timing-equalized with a real
 * bcrypt cost-12 dummy hash (§10 — not a malformed constant).
 */
final class AdminStore
{
    // real bcrypt (cost 12) of a random throwaway string; replaced at deploy
    private const DUMMY_HASH = '$2y$12$A0PDSlFSlKPt4FcuvNtfGeZAO9OmvDZO4Ukf/14mMq9FMegjOlO2m';

    public function __construct(private readonly Config $config)
    {
    }

    public function exists(): bool
    {
        return is_file($this->file());
    }

    /** First-run convenience: create admin.json from .env if it is missing. */
    public function ensureBootstrap(): void
    {
        if ($this->exists()) {
            return;
        }
        $u = $_ENV['ADMIN_USERNAME'] ?? $_SERVER['ADMIN_USERNAME'] ?? getenv('ADMIN_USERNAME');
        $h = $_ENV['ADMIN_PASSWORD_HASH'] ?? $_SERVER['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH');
        if (!is_string($u) || $u === '' || !is_string($h) || $h === '') {
            return;
        }
        $this->create($u, $h);
    }

    public function create(string $username, string $passwordHash): void
    {
        $this->persist([
            'username' => $username,
            'password_hash' => $passwordHash,
            'created_at' => date('c'),
        ]);
    }

    /** Change the stored password hash, preserving username + created_at. */
    public function setPassword(string $passwordHash): void
    {
        $record = $this->read();
        $username = is_array($record) && is_string($record['username'] ?? null) ? $record['username'] : '';
        if ($username === '') {
            throw new \RuntimeException('no admin account to update');
        }
        $this->persist([
            'username' => $username,
            'password_hash' => $passwordHash,
            'created_at' => is_string($record['created_at'] ?? null) ? $record['created_at'] : date('c'),
            'updated_at' => date('c'),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function persist(array $payload): void
    {
        $file = $this->file();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot write admin file');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot move admin file into place');
        }
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        if (!$this->exists()) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($this->file()), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function verify(string $username, string $password): bool
    {
        $record = $this->read();

        $knownUser = is_array($record) && is_string($record['username'] ?? null)
            && hash_equals($record['username'], $username);
        $hash = $knownUser && is_string($record['password_hash'] ?? null)
            ? $record['password_hash']
            : self::DUMMY_HASH;

        // password_verify always runs — same cost for unknown user / bad password
        $ok = password_verify($password, $hash);

        return $ok && $knownUser;
    }

    private function file(): string
    {
        return (string) $this->config->get('admin_file');
    }
}
