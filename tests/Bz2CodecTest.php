<?php
namespace Kyte\Test;

use Kyte\Mcp\Util\Bz2Codec;
use PHPUnit\Framework\TestCase;

/**
 * Focused tests for the bz2 helper that read_controller / read_function /
 * read_page run their content fields through. The decompression itself is
 * the easy part — the value of these tests is pinning down the
 * pass-through behavior on rows that aren't compressed (older data,
 * empty fields, accidental plain-text inserts), since the same code path
 * has to serve all of them without losing data.
 */
class Bz2CodecTest extends TestCase
{
    public function testDecompressesValidBz2Data(): void
    {
        $original = "<?php\nclass Foo {}\n";
        $compressed = bzcompress($original, 9);

        $this->assertSame($original, Bz2Codec::decompressIfBz2($compressed));
    }

    public function testReturnsRawDataWhenNotBz2(): void
    {
        $plain = "// already decompressed source\nfunction foo() {}\n";
        $this->assertSame($plain, Bz2Codec::decompressIfBz2($plain));
    }

    public function testHandlesEmptyAndNullInput(): void
    {
        $this->assertSame('', Bz2Codec::decompressIfBz2(null));
        $this->assertSame('', Bz2Codec::decompressIfBz2(''));
        $this->assertSame('B', Bz2Codec::decompressIfBz2('B')); // single byte, can't be BZ
    }

    public function testReturnsRawDataWhenBz2DecompressionFails(): void
    {
        // Looks like bz2 (BZ prefix) but is corrupted — should fall back to
        // returning the raw blob rather than blanking the field.
        $broken = "BZ" . str_repeat("\x00", 30);
        $result = Bz2Codec::decompressIfBz2($broken);
        $this->assertSame($broken, $result);
    }
}
