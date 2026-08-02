<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Media asset tools (list / read / create / delete) for a Kyte site's media
 * library (KYTE-#345 tool parity).
 *
 * Media is SITE-scoped: each file lives in the site's dedicated S3 media bucket,
 * reached via the site's region + the owning application's AWS credentials.
 *
 * Uploads: the human Shipyard path uses a presigned browser-direct POST. That
 * doesn't fit an AI client, so create_media takes the bytes as base64 and the
 * server writes them to S3 directly (Kyte\Aws\S3::write). A modest size cap
 * keeps this off the "bulk upload" path — large files should still go through
 * the Shipyard uploader. Downloads (read_media) return a short-lived presigned
 * GET URL; delete removes the S3 object + the row.
 *
 * Reads use `read`; create/delete use `provision` (site-scoped resources, same
 * scope as the site tools). Every id is re-scoped to the token's account first.
 */
final class MediaTools
{
    /**
     * Max decoded upload size via MCP (5 MB). base64 in a tool call is not a
     * bulk-transfer channel — this covers icons/logos/images without letting a
     * single call balloon. Larger assets: Shipyard uploader.
     */
    private const MAX_UPLOAD_BYTES = 5242880;

    public function __construct(private readonly Api $api)
    {
    }

    /**
     * List the media files in a site's library (metadata only).
     *
     * @param int $site_id Site id (from list_sites).
     * @return array{media: array<int, array<string,mixed>>}
     */
    #[McpTool(name: 'list_media', description: 'List the media files in a Kyte site\'s library (metadata only — call read_media for a download URL).')]
    #[RequiresScope('read')]
    public function listMedia(int $site_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['media' => []];
        }

        $model = new \Kyte\Core\Model(\Media);
        $model->retrieve('site', $site_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($model->objects as $md) {
            $out[] = $this->mediaToArray($md);
        }
        return ['media' => $out];
    }

    /**
     * Read a single media file's metadata plus a short-lived presigned download
     * URL (valid ~60 minutes).
     *
     * @param int $media_id Media id (from list_media).
     * @return array<string,mixed>|null
     */
    #[McpTool(name: 'read_media', description: 'Read a media file\'s metadata plus a short-lived presigned download URL.')]
    #[RequiresScope('read')]
    public function readMedia(int $media_id): ?array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return null;
        }
        $media = new \Kyte\Core\ModelObject(\Media);
        if (!$media->retrieve('id', $media_id) || (int)$media->kyte_account !== $accountId) {
            return null;
        }

        $out = $this->mediaToArray($media);
        $s3ctx = $this->resolveSiteS3((int)$media->site, $accountId);
        if ($s3ctx !== null && $media->s3key) {
            try {
                $out['download_url'] = $s3ctx->getObject((string)$media->s3key);
            } catch (\Throwable $e) {
                $out['download_url'] = null;
            }
        }
        return $out;
    }

    /**
     * Upload a media file to a site's library. Pass the file bytes as base64.
     * The server writes them to the site's S3 media bucket directly.
     *
     * @param int         $site_id        Site id (from list_sites). Must be provisioned (have a media bucket).
     * @param string      $filename       File name (e.g. "logo.png"); sanitized to a safe key.
     * @param string      $content_base64 The file bytes, base64-encoded. Max 5 MB decoded.
     * @param string|null $content_type   MIME type (e.g. "image/png"); optional but recommended.
     * @return array{created: bool, media?: array<string,mixed>, error?: string}
     */
    #[McpTool(name: 'create_media', description: 'Upload a media file (base64 bytes, max 5MB) to a Kyte site\'s S3 media library. Provide content_type (e.g. image/png) when known.')]
    #[RequiresScope('provision')]
    public function createMedia(int $site_id, string $filename, string $content_base64, ?string $content_type = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['created' => false, 'error' => 'Site not found in this account.'];
        }

        $data = base64_decode($content_base64, true);
        if ($data === false) {
            return ['created' => false, 'error' => 'content_base64 is not valid base64.'];
        }
        if ($data === '') {
            return ['created' => false, 'error' => 'content_base64 decoded to empty.'];
        }
        if (strlen($data) > self::MAX_UPLOAD_BYTES) {
            return ['created' => false, 'error' => 'File exceeds the 5MB MCP upload limit; use the Shipyard uploader for larger files.'];
        }

        $s3 = $this->resolveSiteS3($site_id, $accountId);
        if ($s3 === null) {
            return ['created' => false, 'error' => 'Site is not fully provisioned yet (no media bucket). Poll read_site until status is "active".'];
        }

        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '-', $filename);
        if ($safe === null || $safe === '') {
            return ['created' => false, 'error' => 'Invalid filename.'];
        }
        $key = date('Y-m-d') . '/' . $safe;

        $media = new \Kyte\Core\ModelObject(\Media);
        try {
            $media->create([
                'name'         => $safe,
                's3key'        => $key,
                'site'         => $site_id,
                'kyte_account' => $accountId,
            ]);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => 'Failed to create media record: ' . $e->getMessage()];
        }

        // Server-side upload; roll the row back if S3 rejects it.
        try {
            $s3->write($key, $data, $content_type);
        } catch (\Throwable $e) {
            try { $media->delete(); } catch (\Throwable $ignore) {}
            return ['created' => false, 'error' => 'Upload failed: ' . $e->getMessage()];
        }

        return ['created' => true, 'media' => $this->mediaToArray($media)];
    }

    /**
     * Delete a media file (removes both the S3 object and the record).
     *
     * @param int $media_id Media id.
     * @return array{deleted: bool, media_id?: int, error?: string}
     */
    #[McpTool(name: 'delete_media', description: 'Delete a media file — removes both the S3 object and the record.')]
    #[RequiresScope('provision')]
    public function deleteMedia(int $media_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return ['deleted' => false, 'error' => 'Media not found in this account.'];
        }
        $media = new \Kyte\Core\ModelObject(\Media);
        if (!$media->retrieve('id', $media_id) || (int)$media->kyte_account !== $accountId) {
            return ['deleted' => false, 'error' => 'Media not found in this account.'];
        }

        $key    = $media->s3key ? (string)$media->s3key : '';
        $siteId = (int)$media->site;

        $media->delete();

        // Best-effort S3 cleanup (the row is already gone; a stray object is
        // harmless and surfaced nowhere).
        if ($key !== '') {
            $s3 = $this->resolveSiteS3($siteId, $accountId);
            if ($s3 !== null) {
                try { $s3->unlink($key); } catch (\Throwable $e) {}
            }
        }

        return ['deleted' => true, 'media_id' => $media_id];
    }

    /**
     * Build an S3 client for a site's media bucket (site region + owning app's
     * AWS creds), or null if the site isn't ours / not yet provisioned.
     */
    private function resolveSiteS3(int $siteId, int $accountId): ?\Kyte\Aws\S3
    {
        $site = new \Kyte\Core\ModelObject(\KyteSite);
        if (!$site->retrieve('id', $siteId) || (int)$site->kyte_account !== $accountId) {
            return null;
        }
        if (empty($site->s3MediaBucketName) || empty($site->region)) {
            return null;
        }
        $app = new \Kyte\Core\ModelObject(\Application);
        if (!$app->retrieve('id', (int)$site->application)) {
            return null;
        }
        $credentials = new \Kyte\Aws\Credentials((string)$site->region, $app->aws_public_key, $app->aws_private_key);
        return new \Kyte\Aws\S3($credentials, (string)$site->s3MediaBucketName);
    }

    /** @return array<string,mixed> */
    private function mediaToArray(\Kyte\Core\ModelObject $m): array
    {
        // isset() (not `?? ` / `!== null`) so a freshly-created object that
        // never populated an optional column (e.g. thumbnail) doesn't emit an
        // undefined-property warning into the response stream.
        return [
            'id'        => (int)$m->id,
            'name'      => isset($m->name) ? (string)$m->name : '',
            's3key'     => isset($m->s3key) ? (string)$m->s3key : null,
            'thumbnail' => isset($m->thumbnail) ? (string)$m->thumbnail : null,
            'site'      => isset($m->site) ? (int)$m->site : null,
        ];
    }

    private function accountIdOrZero(): int
    {
        return isset($this->api->account->id) ? (int)$this->api->account->id : 0;
    }

    private function siteBelongsToAccount(int $siteId, int $accountId): bool
    {
        $site = new \Kyte\Core\ModelObject(\KyteSite);
        return $site->retrieve('id', $siteId) && (int)$site->kyte_account === $accountId;
    }
}
