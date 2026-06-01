<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Kyte\Mcp\Service\DraftService;
use Mcp\Capability\Attribute\McpTool;

/**
 * Draft authoring tools for the MCP draft/write feature.
 *
 * A draft is a pending, non-live edit. `write_page_part` accumulates content
 * changes into a single open draft per page (draft=1, is_current=0) WITHOUT
 * touching the live page — a human (or a commit-scoped tool) promotes it
 * later. `list_drafts`/`read_draft` support review; `discard_draft` drops a
 * draft. Commit (promotion + publish) is a separate commit-scoped tool.
 *
 * All work is delegated to the surface-generic DraftService; this class only
 * supplies the page surface and shapes tool I/O. Functions and scripts will
 * add sibling tools over the same service with their own surface descriptors.
 *
 * Scope mapping: write/discard require 'draft'; list/read require 'read'.
 * Account scoping is enforced inside the service on every caller-supplied id.
 */
final class DraftTools
{
    public function __construct(private readonly Api $api)
    {
    }

    private function service(): DraftService
    {
        return new DraftService($this->api);
    }

    /**
     * Create or update a draft edit of a page part (does NOT publish).
     *
     * Repeated calls for the same page accumulate into one open draft: each
     * call starts from the page's current live content (or the in-progress
     * draft), sets the named part, and re-stores. Use commit_draft to publish.
     *
     * @param int    $page_id KytePage id from list_pages.
     * @param string $part    One of: html, stylesheet, javascript (aliases: css, js).
     * @param string $content Full new content for that part.
     * @return array{ok:bool, draft_id?:int, parent_id?:int, version_number?:int, part?:string, created?:bool, content_bytes?:int, error?:string}
     */
    #[McpTool(name: 'write_page_part', description: 'Create or update a DRAFT edit of a page part (html, stylesheet, or javascript). Does not publish — the draft is held for review and promoted via commit_draft. Repeated calls on the same page accumulate into one draft.')]
    #[RequiresScope('draft')]
    public function writePagePart(int $page_id, string $part, string $content): array
    {
        $result = $this->service()->writePart(DraftService::pageSurface(), $page_id, $part, $content);
        if ($result === null) {
            return [
                'ok'    => false,
                'error' => "Could not write draft: page {$page_id} was not found in this account, or '{$part}' is not a writable part. Valid parts: html, stylesheet, javascript.",
            ];
        }
        return array_merge(['ok' => true], $result);
    }

    /**
     * List open drafts in an application (pages, for now).
     *
     * @param int $application_id Application id from list_applications.
     * @return array{drafts: array<int, array{draft_id:int, parent_id:int, version_number:int, draft_source:?string, date_modified:?int}>}
     */
    #[McpTool(name: 'list_drafts', description: 'List pending (uncommitted) drafts in a Kyte application. Use read_draft to inspect one and commit_draft to publish it.')]
    #[RequiresScope('read')]
    public function listDrafts(int $application_id): array
    {
        return $this->service()->listDrafts(DraftService::pageSurface(), $application_id);
    }

    /**
     * Read a draft's content and which parts differ from the live page.
     *
     * @param int $draft_id KytePageVersion id of a draft (from list_drafts).
     * @return array{draft_id:int, parent_id:int, version_number:int, draft_source:?string, content:array<string,string>, changed_parts:array<int,string>}|null
     */
    #[McpTool(name: 'read_draft', description: 'Read a pending draft: its content (html/stylesheet/javascript) and which parts differ from the current live version.')]
    #[RequiresScope('read')]
    public function readDraft(int $draft_id): ?array
    {
        return $this->service()->readDraft(DraftService::pageSurface(), $draft_id);
    }

    /**
     * Discard a pending draft (soft-delete; does not affect the live page).
     *
     * @param int $draft_id KytePageVersion id of a draft (from list_drafts).
     * @return array{discarded:bool, draft_id:int}|null
     */
    #[McpTool(name: 'discard_draft', description: 'Discard a pending draft. Does not affect the live page. Returns null if the draft does not exist in this account.')]
    #[RequiresScope('draft')]
    public function discardDraft(int $draft_id): ?array
    {
        return $this->service()->discardDraft(DraftService::pageSurface(), $draft_id);
    }

    /**
     * Commit (publish) a draft to the live site.
     *
     * This is the ONLY draft tool that changes the live page: it writes the
     * draft's content live, publishes to S3, invalidates CloudFront, and makes
     * the draft the new current version — identical to a human clicking
     * Publish. Requires the 'commit' scope (tokens are draft-only by default).
     *
     * @param int $draft_id KytePageVersion id of a draft (from list_drafts).
     * @return array{committed:bool, draft_id:int, parent_id:int, version_number:int, site_id:?int, s3key:?string}|null
     */
    #[McpTool(name: 'commit_draft', description: 'Publish a pending draft to the live site (writes live content, pushes to S3, invalidates CloudFront) and make it the current version. This is the only draft action that affects the live page. Requires the commit scope.')]
    #[RequiresScope('commit')]
    public function commitDraft(int $draft_id): ?array
    {
        return $this->service()->commitDraft(DraftService::pageSurface(), $draft_id);
    }
}
