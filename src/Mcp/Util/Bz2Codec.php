<?php
namespace Kyte\Mcp\Util;

/**
 * Bzip2 decode helper for code/content fields.
 *
 * Several Kyte models store user-authored payloads (Controller.code,
 * Function.code, KytePageVersionContent.{html,stylesheet,javascript},
 * KyteScript variants) as bzip2-compressed BLOBs. The compression is
 * applied transparently in the Shipyard write path; reads from MCP need
 * to undo it before serializing, otherwise json_encode trips on invalid
 * UTF-8 bytes and the entire response is silently dropped.
 *
 * The 'BZ' magic prefix identifies bzip2 data; everything else is
 * passed through unchanged so the helper is safe to apply to fields
 * that may legitimately not be compressed (older rows, fresh empty
 * inserts, etc.). bzdecompress is suppressed and falls back to the raw
 * bytes if it can't decompress — better to return the raw blob than
 * blank the field on a partially-corrupted row.
 */
final class Bz2Codec
{
    public static function decompressIfBz2(?string $data): string
    {
        if ($data === null || strlen($data) < 2) {
            return (string)$data;
        }
        if (substr($data, 0, 2) !== 'BZ') {
            return $data;
        }
        $decompressed = @bzdecompress($data);
        return is_string($decompressed) ? $decompressed : $data;
    }
}
