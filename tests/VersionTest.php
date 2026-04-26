<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Kyte\Core\Version::get().
 *
 * The previous implementation hardcoded MAJOR/MINOR/PATCH constants that
 * were never bumped — every install reported v4.1.1 regardless of what
 * composer actually pulled. v4.3.2 replaced that with a Composer\InstalledVersions
 * lookup; these tests pin the new contract.
 */
class VersionTest extends TestCase
{
    public function testGetReturnsNonEmptyString(): void
    {
        $version = \Kyte\Core\Version::get();
        $this->assertIsString($version);
        $this->assertNotSame('', $version);
    }

    public function testGetReturnsComposerInstalledVersion(): void
    {
        // Composer's runtime data is always present in a composer-installed
        // tree (which the test suite is). Confirm we surface its pretty
        // version, not the FALLBACK.
        $expected = \Composer\InstalledVersions::getPrettyVersion('keyqcloud/kyte-php');
        $this->assertSame($expected, \Kyte\Core\Version::get());
    }

    public function testGetDoesNotReturnHardcodedLegacyString(): void
    {
        // Guard against a regression where someone reintroduces the
        // pre-v4.3.2 hardcoded "v4.1.1" string.
        $this->assertNotSame('v4.1.1', \Kyte\Core\Version::get());
    }
}
