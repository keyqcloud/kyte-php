<?php
namespace Kyte\Core;

/**
 * Reports the running kyte-php version.
 *
 * Reads Composer's runtime installed-package data via Composer\InstalledVersions
 * (Composer 2.x API). Returns the pretty version string composer resolved:
 *
 *   - Tagged install:    "v4.3.2"
 *   - Branch install:    "dev-master" / "dev-feature/phase-2-mcp-tokens"
 *   - Dev tree (no composer registration): "unknown"
 *
 * Replaces the old hardcoded MAJOR/MINOR/PATCH constants, which went stale
 * (v4.1.1 was reported on v4.3.x installs because nobody bumped the consts).
 */
class Version
{
    private const FALLBACK = 'unknown';
    private const PACKAGE  = 'keyqcloud/kyte-php';

    public static function get(): string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return self::FALLBACK;
        }

        try {
            $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\OutOfBoundsException $e) {
            // Package not registered in Composer's runtime data — happens
            // when running a dev tree that hasn't been composer-installed
            // (e.g. directly from a git clone).
            return self::FALLBACK;
        }

        return $version ?? self::FALLBACK;
    }
}
