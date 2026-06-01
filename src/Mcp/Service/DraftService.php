<?php
namespace Kyte\Mcp\Service;

use Kyte\Core\Api;
use Kyte\Core\Model;
use Kyte\Core\ModelObject;
use Kyte\Mcp\Util\Bz2Codec;

/**
 * Surface-generic draft engine for the MCP draft/write feature.
 *
 * Pages, controller functions, and scripts all share one version pattern:
 * a *Version table (is_current flag) plus a separate *VersionContent table
 * keyed by a sha256 content_hash, with the content fields bzip2-compressed
 * and a reference_count for dedup. This service owns the draft lifecycle
 * for any such surface — it is driven by a "surface descriptor" (see
 * pageSurface()) so adding functions/scripts later is a new descriptor,
 * not new engine code.
 *
 * A DRAFT is a pending version that is NOT live: draft=1, is_current=0.
 * The parent entity's live content and its is_current version are left
 * untouched until the draft is committed. There is at most ONE open draft
 * per parent entity — repeated write_*_part calls accumulate into that same
 * draft (start from the current live content, apply one part, re-store).
 *
 * The content_hash is the source of truth for a version's content (mirrors
 * how read_page resolves content via KytePageVersionContent), so this
 * service does not populate the version row's inline per-field columns; it
 * only points content_hash at the stored content. The hash is computed over
 * the RAW (uncompressed) content exactly like KytePageController so a draft
 * that matches existing content de-duplicates against it.
 *
 * Commit (promotion to live + publish) is intentionally NOT here — it is
 * surface-specific (pages publish to S3/CF, functions regenerate code,
 * scripts publish a script) and lives with each surface's commit strategy.
 */
final class DraftService
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * Descriptor for the KytePage surface.
     *
     * @return array{versionModel:array, contentModel:array, parentModel:array, fk:string, contentFields:array<int,string>, writableParts:array<int,string>, label:string}
     */
    public static function pageSurface(): array
    {
        return [
            'versionModel'  => \KytePageVersion,
            'contentModel'  => \KytePageVersionContent,
            'parentModel'   => \KytePage,
            'fk'            => 'page',
            // Order matters: must match KytePageController::generateContentHash.
            'contentFields' => ['html', 'stylesheet', 'javascript', 'block_layout'],
            // Parts a write tool may set (block_layout is internal/legacy).
            'writableParts' => ['html', 'stylesheet', 'javascript'],
            'label'         => 'page',
        ];
    }

    private function accountId(): int
    {
        return isset($this->api->account->id) ? (int)$this->api->account->id : 0;
    }

    private function userIdOrNull(): ?int
    {
        return isset($this->api->user->id) ? (int)$this->api->user->id : null;
    }

    /**
     * Verify a parent entity (page/function/script) belongs to the token's
     * account. Caller-supplied ids must always be re-scoped.
     */
    public function parentBelongsToAccount(array $surface, int $parentId): bool
    {
        $accountId = $this->accountId();
        if ($accountId === 0) {
            return false;
        }
        $parent = new ModelObject($surface['parentModel']);
        return $parent->retrieve('id', $parentId) && (int)$parent->kyte_account === $accountId;
    }

    /**
     * The single open draft for a parent (draft=1, is_current=0), or null.
     */
    public function openDraft(array $surface, int $parentId): ?ModelObject
    {
        $version = new ModelObject($surface['versionModel']);
        $found = $version->retrieve($surface['fk'], $parentId, [
            ['field' => 'draft',        'value' => 1],
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ]);
        return $found ? $version : null;
    }

    /**
     * Decompressed content map for a given version object (by content_hash).
     * Missing/orphaned content yields empty strings for every content field.
     *
     * @return array<string,string>
     */
    public function versionContent(array $surface, ModelObject $version): array
    {
        $map = [];
        foreach ($surface['contentFields'] as $f) {
            $map[$f] = '';
        }
        $content = new ModelObject($surface['contentModel']);
        if ($content->retrieve('content_hash', (string)$version->content_hash, [
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ])) {
            foreach ($surface['contentFields'] as $f) {
                $map[$f] = Bz2Codec::decompressIfBz2($content->$f);
            }
        }
        return $map;
    }

    /**
     * The current LIVE content for a parent (its is_current version), used as
     * the base when starting a fresh draft. Empty strings if no current
     * version exists yet.
     *
     * @return array<string,string>
     */
    public function currentLiveContent(array $surface, int $parentId): array
    {
        $version = new ModelObject($surface['versionModel']);
        $found = $version->retrieve($surface['fk'], $parentId, [
            ['field' => 'is_current',   'value' => 1],
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ]);
        if ($found) {
            return $this->versionContent($surface, $version);
        }
        $map = [];
        foreach ($surface['contentFields'] as $f) {
            $map[$f] = '';
        }
        return $map;
    }

    /**
     * sha256 over the RAW content fields, in descriptor order — identical to
     * KytePageController::generateContentHash so drafts dedup against
     * existing version content.
     *
     * @param array<string,string> $content
     */
    private function contentHash(array $surface, array $content): string
    {
        $s = '';
        foreach ($surface['contentFields'] as $f) {
            $s .= isset($content[$f]) ? $content[$f] : '';
        }
        return hash('sha256', $s);
    }

    /**
     * Store a content map (or reference it if an identical hash already
     * exists) and return its content_hash. Mirrors storeVersionContent /
     * incrementContentReference in KytePageController.
     *
     * @param array<string,string> $content
     */
    private function storeContent(array $surface, array $content): string
    {
        $hash = $this->contentHash($surface, $content);

        $existing = new ModelObject($surface['contentModel']);
        if ($existing->retrieve('content_hash', $hash, [
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ])) {
            $existing->save([
                'reference_count' => (int)$existing->reference_count + 1,
                'last_referenced' => time(),
            ]);
            return $hash;
        }

        $row = ['content_hash' => $hash];
        foreach ($surface['contentFields'] as $f) {
            $row[$f] = isset($content[$f]) ? bzcompress($content[$f], 9) : null;
        }
        $row['reference_count'] = 1;
        $row['last_referenced'] = time();
        $row['kyte_account']    = $this->accountId();

        $obj = new ModelObject($surface['contentModel']);
        if (!$obj->create($row)) {
            throw new \RuntimeException('Unable to store draft content.');
        }
        return $hash;
    }

    /**
     * Drop a reference to a content hash (when a draft repoints away from it
     * or is discarded). Never deletes the row or goes below zero — orphan
     * cleanup is handled by the existing last_referenced sweep, not here.
     */
    private function releaseContent(array $surface, string $hash): void
    {
        if ($hash === '') {
            return;
        }
        $content = new ModelObject($surface['contentModel']);
        if ($content->retrieve('content_hash', $hash, [
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ])) {
            $count = (int)$content->reference_count;
            $content->save([
                'reference_count' => $count > 0 ? $count - 1 : 0,
                'last_referenced' => time(),
            ]);
        }
    }

    /**
     * Next version_number for a parent (max across all versions + 1, drafts
     * included), mirroring KytePageController::getNextVersionNumber.
     */
    private function nextVersionNumber(array $surface, int $parentId): int
    {
        $last = new Model($surface['versionModel']);
        $last->retrieve($surface['fk'], $parentId, false, [
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ], false, [['field' => 'version_number', 'direction' => 'desc']], 1);
        if ($last->count() > 0) {
            return (int)$last->objects[0]->version_number + 1;
        }
        return 1;
    }

    /**
     * Create or update the open draft for a parent with one content part set
     * to $content. Returns a small summary (or null if denied / invalid part).
     *
     * @return array{draft_id:int, parent_id:int, version_number:int, part:string, created:bool, content_bytes:int}|null
     */
    public function writePart(array $surface, int $parentId, string $part, string $content): ?array
    {
        if (!$this->parentBelongsToAccount($surface, $parentId)) {
            return null;
        }

        $part = $this->normalizePart($surface, $part);
        if ($part === null) {
            return null;
        }

        $draft = $this->openDraft($surface, $parentId);

        // Base = current draft content if a draft is open, else current live.
        $base = $draft !== null
            ? $this->versionContent($surface, $draft)
            : $this->currentLiveContent($surface, $parentId);

        $base[$part] = $content;
        $newHash = $this->storeContent($surface, $base);

        if ($draft !== null) {
            $oldHash = (string)$draft->content_hash;
            $draft->save([
                'content_hash' => $newHash,
                'draft_source' => 'mcp',
            ]);
            if ($oldHash !== $newHash) {
                $this->releaseContent($surface, $oldHash);
            }
            return [
                'draft_id'       => (int)$draft->id,
                'parent_id'      => $parentId,
                'version_number' => (int)$draft->version_number,
                'part'           => $part,
                'created'        => false,
                'content_bytes'  => strlen($content),
            ];
        }

        $version = new ModelObject($surface['versionModel']);
        $created = $version->create([
            $surface['fk']     => $parentId,
            'version_number'   => $this->nextVersionNumber($surface, $parentId),
            'version_type'     => 'mcp_draft',
            'content_hash'     => $newHash,
            'is_current'       => 0,
            'draft'            => 1,
            'draft_source'     => 'mcp',
            'kyte_account'     => $this->accountId(),
            'created_by'       => $this->userIdOrNull(),
        ]);
        if (!$created) {
            throw new \RuntimeException('Unable to create draft.');
        }
        return [
            'draft_id'       => (int)$version->id,
            'parent_id'      => $parentId,
            'version_number' => (int)$version->version_number,
            'part'           => $part,
            'created'        => true,
            'content_bytes'  => strlen($content),
        ];
    }

    /**
     * Load a draft version by id, re-scoped to the account and asserted to be
     * an open draft. Null if not found / not a draft / wrong account.
     */
    public function loadDraft(array $surface, int $draftId): ?ModelObject
    {
        $version = new ModelObject($surface['versionModel']);
        if (!$version->retrieve('id', $draftId, [
            ['field' => 'draft',        'value' => 1],
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ])) {
            return null;
        }
        return $version;
    }

    /**
     * Draft content + a diff (which parts differ from the current live
     * version). Null if the draft doesn't exist / belong to the account.
     *
     * @return array{draft_id:int, parent_id:int, version_number:int, draft_source:?string, content:array<string,string>, changed_parts:array<int,string>}|null
     */
    public function readDraft(array $surface, int $draftId): ?array
    {
        $draft = $this->loadDraft($surface, $draftId);
        if ($draft === null) {
            return null;
        }
        $parentId   = (int)$draft->{$surface['fk']};
        $draftMap   = $this->versionContent($surface, $draft);
        $liveMap    = $this->currentLiveContent($surface, $parentId);

        $changed = [];
        foreach ($surface['writableParts'] as $part) {
            if (($draftMap[$part] ?? '') !== ($liveMap[$part] ?? '')) {
                $changed[] = $part;
            }
        }

        $content = [];
        foreach ($surface['writableParts'] as $part) {
            $content[$part] = $draftMap[$part] ?? '';
        }

        return [
            'draft_id'       => (int)$draft->id,
            'parent_id'      => $parentId,
            'version_number' => (int)$draft->version_number,
            'draft_source'   => $draft->draft_source !== null ? (string)$draft->draft_source : null,
            'content'        => $content,
            'changed_parts'  => $changed,
        ];
    }

    /**
     * Discard (soft-delete) a draft and release its content reference.
     *
     * @return array{discarded:bool, draft_id:int}|null
     */
    public function discardDraft(array $surface, int $draftId): ?array
    {
        $draft = $this->loadDraft($surface, $draftId);
        if ($draft === null) {
            return null;
        }
        $hash = (string)$draft->content_hash;
        $draft->delete();
        $this->releaseContent($surface, $hash);
        return ['discarded' => true, 'draft_id' => $draftId];
    }

    /**
     * Commit a draft: publish its content to live and promote it to the
     * current version. This is the ONLY draft operation that touches the live
     * site. Publishing reuses the real KytePageController pipeline (live
     * KytePageData write + S3 + CloudFront), so the result is byte-identical
     * to a human Publish. Null if the draft doesn't exist / belong to the
     * account.
     *
     * @return array{committed:bool, draft_id:int, parent_id:int, version_number:int, site_id:?int, s3key:?string}|null
     */
    public function commitDraft(array $surface, int $draftId): ?array
    {
        // Publish strategy is surface-specific; only pages are wired so far.
        if ($surface['label'] !== 'page') {
            return null;
        }

        $draft = $this->loadDraft($surface, $draftId);
        if ($draft === null) {
            return null;
        }
        $parentId = (int)$draft->{$surface['fk']};

        // Re-scope the page and load it as the publish target.
        $page = new ModelObject($surface['parentModel']);
        if (!$page->retrieve('id', $parentId) || (int)$page->kyte_account !== $this->accountId()) {
            return null;
        }

        $content = $this->versionContent($surface, $draft);

        // Publish through the real controller pipeline. Construct it in
        // internal mode (no HTTP session) — account context comes from $api.
        // The controller constructor takes $api by reference; bind a local.
        $api  = $this->api;
        $resp = [];
        $controller = new \Kyte\Mvc\Controller\KytePageController(\KytePage, $api, 'm/d/Y H:i:s', $resp, true);
        $pub = $controller->publishFromContent($page, $content);

        // Promote: demote the prior current version, flip this draft to live.
        $this->markCurrentNotCurrent($surface, $parentId);
        $draft->save([
            'is_current'   => 1,
            'draft'        => 0,
            'version_type' => 'mcp_commit',
        ]);

        return [
            'committed'      => true,
            'draft_id'       => $draftId,
            'parent_id'      => $parentId,
            'version_number' => (int)$draft->version_number,
            'site_id'        => isset($pub['site_id']) ? (int)$pub['site_id'] : null,
            's3key'          => isset($pub['s3key']) ? (string)$pub['s3key'] : null,
        ];
    }

    /**
     * Demote the parent's current version (is_current=1 → 0), if any.
     */
    private function markCurrentNotCurrent(array $surface, int $parentId): void
    {
        $cur = new ModelObject($surface['versionModel']);
        if ($cur->retrieve($surface['fk'], $parentId, [
            ['field' => 'is_current',   'value' => 1],
            ['field' => 'kyte_account', 'value' => $this->accountId()],
        ])) {
            $cur->save(['is_current' => 0]);
        }
    }

    /**
     * List open drafts whose parent lives under the given application. Walks
     * draft versions in account scope and filters by the parent's site/app.
     * For the page surface, parent → KytePage → KyteSite(application).
     *
     * @return array{drafts: array<int, array{draft_id:int, parent_id:int, version_number:int, draft_source:?string, date_modified:?int}>}
     */
    public function listDrafts(array $surface, int $applicationId): array
    {
        $accountId = $this->accountId();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($applicationId, $accountId)) {
            return ['drafts' => []];
        }

        $drafts = new Model($surface['versionModel']);
        $drafts->retrieve(null, null, false, [
            ['field' => 'draft',        'value' => 1],
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($drafts->objects as $d) {
            $parentId = (int)$d->{$surface['fk']};
            if (!$this->parentInApplication($surface, $parentId, $applicationId, $accountId)) {
                continue;
            }
            $out[] = [
                'draft_id'       => (int)$d->id,
                'parent_id'      => $parentId,
                'version_number' => (int)$d->version_number,
                'draft_source'   => $d->draft_source !== null ? (string)$d->draft_source : null,
                'date_modified'  => $d->date_modified !== null ? (int)$d->date_modified : null,
            ];
        }
        return ['drafts' => $out];
    }

    private function applicationBelongsToAccount(int $applicationId, int $accountId): bool
    {
        $app = new ModelObject(\Application);
        return $app->retrieve('id', $applicationId) && (int)$app->kyte_account === $accountId;
    }

    /**
     * Whether a page parent belongs to the given application (page → site →
     * application). Page-surface specific for now; functions/scripts will
     * supply their own parent→app resolution when those surfaces land.
     */
    private function parentInApplication(array $surface, int $parentId, int $applicationId, int $accountId): bool
    {
        if ($surface['label'] !== 'page') {
            return false;
        }
        $page = new ModelObject(\KytePage);
        if (!$page->retrieve('id', $parentId) || (int)$page->kyte_account !== $accountId) {
            return false;
        }
        $site = new ModelObject(\KyteSite);
        if (!$site->retrieve('id', (int)$page->site) || (int)$site->kyte_account !== $accountId) {
            return false;
        }
        return (int)$site->application === $applicationId;
    }

    /**
     * Validate/normalize a requested part against the surface's writable
     * parts. Accepts a few ergonomic aliases (css, js). Null if unsupported.
     */
    private function normalizePart(array $surface, string $part): ?string
    {
        $aliases = ['css' => 'stylesheet', 'js' => 'javascript', 'script' => 'javascript'];
        $p = strtolower(trim($part));
        $p = $aliases[$p] ?? $p;
        return in_array($p, $surface['writableParts'], true) ? $p : null;
    }
}
