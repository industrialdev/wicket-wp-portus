<?php

declare(strict_types=1);

namespace WicketPortus\Contracts;

/**
 * Opt-in interface for modules that can strip sensitive data from their payload.
 *
 * Modules implementing this interface support "template" export mode, which
 * produces a sanitised manifest suitable for onboarding a new client site.
 * Credentials, API keys, and environment-specific URLs are removed; structural
 * configuration is preserved.
 */
interface SanitizableModuleInterface
{
    /**
     * Return a sanitised copy of $payload with sensitive fields removed.
     *
     * The original $payload must not be mutated; return a new array.
     *
     * @param array $payload The raw exported payload for this module.
     * @return array The sanitised payload.
     */
    public function sanitize(array $payload): array;
}
