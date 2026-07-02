<?php

declare(strict_types=1);

namespace EditFront\Security;

/**
 * Session-bound math CAPTCHA for the login form (§13). Shown only after the
 * throttle has seen repeated failures. The question (e.g. "3 + 5") is rendered
 * on the form; the expected sum lives in the session. Verification is one-shot:
 * the stored answer is consumed on every check, so a fresh challenge must be
 * issued for each render — this defeats answer replay.
 */
final class LoginCaptcha
{
    private const SESSION_KEY = 'cms_captcha_sum';

    /** Issue a new challenge, store the answer in the session, return the question. */
    public function issue(): string
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $_SESSION[self::SESSION_KEY] = $a + $b;
        return $a . ' + ' . $b;
    }

    /** One-shot verify: consumes the stored answer regardless of the outcome. */
    public function verify(?string $answer): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_int($expected) || !is_string($answer)) {
            return false;
        }
        $answer = trim($answer);
        return $answer !== '' && ctype_digit($answer) && (int) $answer === $expected;
    }
}
