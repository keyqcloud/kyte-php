<?php
namespace Kyte\Test;

use Kyte\Mvc\Controller\KytePageController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the publish-time defenses against shipping bzip2-compressed
 * section-template content into published HTML (the MCP commit_draft bug):
 *
 *  - decompressSectionTemplate(): the FK-expanded header/footer arrays carry
 *    their content still bzip2-compressed; this must decompress every field
 *    independently (a null block_layout must NOT suppress the others, the old
 *    all-or-nothing isset() guard's latent failure).
 *  - hasBinaryContamination(): the last-line guard in publishPage() that
 *    aborts a publish if raw bzip2 bytes / non-UTF-8 reached the output.
 */
class PublishIntegrityTest extends TestCase
{
    private function decompressSection(array $section): array
    {
        $m = new \ReflectionMethod(KytePageController::class, 'decompressSectionTemplate');
        $m->setAccessible(true);
        $args = [&$section];
        $m->invokeArgs(null, $args);
        return $section;
    }

    public function testDecompressesEveryCompressedField(): void
    {
        $section = [
            'html'         => bzcompress('<b>hi</b>', 9),
            'stylesheet'   => bzcompress('footer{color:red}', 9),
            'javascript'   => bzcompress('var x=1;', 9),
            'block_layout' => bzcompress('{}', 9),
        ];

        $out = $this->decompressSection($section);

        $this->assertSame('<b>hi</b>', $out['html']);
        $this->assertSame('footer{color:red}', $out['stylesheet']);
        $this->assertSame('var x=1;', $out['javascript']);
        $this->assertSame('{}', $out['block_layout']);
    }

    public function testNullBlockLayoutDoesNotSuppressOtherFields(): void
    {
        // Regression for the latent all-or-nothing guard: a missing/null
        // block_layout used to skip decompression of html/stylesheet/javascript
        // entirely, shipping their raw compressed bytes.
        $section = [
            'html'         => bzcompress('<b>hi</b>', 9),
            'stylesheet'   => bzcompress('footer{color:red}', 9),
            'javascript'   => bzcompress('var x=1;', 9),
            'block_layout' => null,
        ];

        $out = $this->decompressSection($section);

        $this->assertSame('<b>hi</b>', $out['html']);
        $this->assertSame('footer{color:red}', $out['stylesheet']);
        $this->assertSame('var x=1;', $out['javascript']);
        $this->assertNull($out['block_layout']);
    }

    public function testLeavesPlaintextSectionUntouched(): void
    {
        $section = [
            'html'       => '<b>hi</b>',
            'stylesheet' => 'footer{color:red}',
        ];

        $this->assertSame($section, $this->decompressSection($section));
    }

    public function testCleanHtmlIsNotFlaggedAsContaminated(): void
    {
        $html = '<!DOCTYPE html><html><head><style>footer{color:#023f81}</style>'
              . '</head><body><p>café — 日本語</p></body></html>';
        $this->assertFalse(KytePageController::hasBinaryContamination($html));
    }

    public function testDetectsEmbeddedBzip2Stream(): void
    {
        // The exact failure mode: a compressed stylesheet concatenated into a
        // <style> block instead of being decompressed first.
        $html = '<style>footer{}' . bzcompress('body{color:red}', 9) . '</style>';
        $this->assertTrue(KytePageController::hasBinaryContamination($html));
    }

    public function testDetectsInvalidUtf8(): void
    {
        $html = '<p>' . chr(0xFF) . '</p>'; // 0xFF is never valid UTF-8
        $this->assertTrue(KytePageController::hasBinaryContamination($html));
    }

    public function testDoesNotFalsePositiveOnLiteralBZhText(): void
    {
        // Prose that merely contains "BZh" (no full bzip2 block magic) and is
        // valid UTF-8 must publish normally.
        $html = '<p>The BZh2 compression algorithm is great.</p>';
        $this->assertFalse(KytePageController::hasBinaryContamination($html));
    }

    public function testEmptyStringIsClean(): void
    {
        $this->assertFalse(KytePageController::hasBinaryContamination(''));
    }
}
