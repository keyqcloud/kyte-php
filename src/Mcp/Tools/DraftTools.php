<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Kyte\Mcp\Service\DraftService;
use Mcp\Capability\Attribute\McpTool;

/**
 * Draft authoring + commit tools for the MCP draft/write feature, across all
 * three draftable surfaces: pages, controller functions, and standalone
 * scripts. A draft is a pending, non-live edit (draft=1, is_current=0); the
 * live resource is untouched until commit_draft publishes it.
 *
 * Writes are surface-specific (a page has parts; functions/scripts have a
 * single content field). The lifecycle tools — read/discard/commit — take a
 * `surface` argument ('page' | 'function' | 'script') because version ids are
 * per-table and collide across surfaces. list_drafts spans all surfaces and
 * tags each row with its surface, so a caller knows what to pass back.
 *
 * Scopes: write/discard require 'draft'; list/read require 'read'; commit
 * requires 'commit' (tokens are draft-only by default). Account scoping is
 * enforced inside the service on every caller-supplied id. All work is
 * delegated to the surface-generic DraftService.
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

    // ----- Writes (draft scope) — one per surface -----

    /**
     * Create/update a DRAFT edit of a page part (html, stylesheet, javascript).
     * Does not publish. Repeated calls on the same page accumulate into one draft.
     *
     * @return array{ok:bool, surface?:string, draft_id?:int, parent_id?:int, version_number?:int, part?:string, created?:bool, content_bytes?:int, error?:string}
     */
    #[McpTool(name: 'write_page_part', description: 'Create or update a DRAFT edit of a page part (html, stylesheet, or javascript). Does not publish — held for review and promoted via commit_draft. Repeated calls on the same page accumulate into one draft.')]
    #[RequiresScope('draft')]
    public function writePagePart(int $page_id, string $part, string $content): array
    {
        return $this->writeResult(DraftService::pageSurface(), $page_id, $part, $content);
    }

    /**
     * Create/update a DRAFT edit of a controller function's code. Does not
     * regenerate the controller until commit_draft.
     *
     * @return array{ok:bool, surface?:string, draft_id?:int, parent_id?:int, version_number?:int, part?:string, created?:bool, content_bytes?:int, error?:string}
     */
    #[McpTool(name: 'write_function_code', description: 'Create or update a DRAFT edit of a controller function\'s PHP code. Does not regenerate the controller — held for review and promoted via commit_draft.')]
    #[RequiresScope('draft')]
    public function writeFunctionCode(int $function_id, string $code): array
    {
        return $this->writeResult(DraftService::functionSurface(), $function_id, 'code', $code);
    }

    /**
     * Create/update a DRAFT edit of a standalone script's content. Does not
     * publish the script until commit_draft.
     *
     * @return array{ok:bool, surface?:string, draft_id?:int, parent_id?:int, version_number?:int, part?:string, created?:bool, content_bytes?:int, error?:string}
     */
    #[McpTool(name: 'write_script_content', description: 'Create or update a DRAFT edit of a standalone script\'s content. Does not publish — held for review and promoted via commit_draft.')]
    #[RequiresScope('draft')]
    public function writeScriptContent(int $script_id, string $content): array
    {
        return $this->writeResult(DraftService::scriptSurface(), $script_id, 'content', $content);
    }

    /**
     * @param array<string,mixed> $surface
     * @return array{ok:bool, surface?:string, draft_id?:int, parent_id?:int, version_number?:int, part?:string, created?:bool, content_bytes?:int, error?:string}
     */
    private function writeResult(array $surface, int $parentId, string $part, string $content): array
    {
        $result = $this->service()->writePart($surface, $parentId, $part, $content);
        if ($result === null) {
            return [
                'ok'    => false,
                'error' => "Could not write draft: {$surface['label']} {$parentId} was not found in this account, or '{$part}' is not a writable part.",
            ];
        }
        return array_merge(['ok' => true, 'surface' => $surface['label']], $result);
    }

    // ----- Review / lifecycle -----

    /**
     * List pending (uncommitted) drafts across ALL surfaces in an application.
     * Each row is tagged with its `surface` — pass that to read/commit/discard.
     *
     * @return array{drafts: array<int, array{surface:string, draft_id:int, parent_id:int, version_number:int, draft_source:?string, date_modified:?int}>}
     */
    #[McpTool(name: 'list_drafts', description: 'List pending (uncommitted) drafts across pages, functions, and scripts in a Kyte application. Each row is tagged with its surface (page/function/script); pass that surface to read_draft / commit_draft / discard_draft.')]
    #[RequiresScope('read')]
    public function listDrafts(int $application_id): array
    {
        $svc = $this->service();
        $all = [];
        foreach (DraftService::allSurfaces() as $surface) {
            $r = $svc->listDrafts($surface, $application_id);
            foreach ($r['drafts'] as $d) {
                $all[] = $d;
            }
        }
        return ['drafts' => $all];
    }

    /**
     * Read a pending draft (its content + which parts differ from live).
     *
     * @param string $surface  page | function | script (from list_drafts).
     * @param int    $draft_id Version id of the draft (from list_drafts).
     * @return array{surface:string, draft_id:int, parent_id:int, version_number:int, draft_source:?string, content:array<string,string>, changed_parts:array<int,string>}|null
     */
    #[McpTool(name: 'read_draft', description: 'Read a pending draft: its content and which parts differ from the current live version. Pass the surface (page/function/script) and draft_id from list_drafts.')]
    #[RequiresScope('read')]
    public function readDraft(string $surface, int $draft_id): ?array
    {
        $s = DraftService::surfaceByLabel($surface);
        if ($s === null) {
            return null;
        }
        $result = $this->service()->readDraft($s, $draft_id);
        if ($result === null) {
            return null;
        }
        return array_merge(['surface' => $s['label']], $result);
    }

    /**
     * Discard a pending draft (soft-delete; does not affect the live resource).
     *
     * @param string $surface  page | function | script (from list_drafts).
     * @param int    $draft_id Version id of the draft.
     * @return array{discarded:bool, draft_id:int}|null
     */
    #[McpTool(name: 'discard_draft', description: 'Discard a pending draft (does not affect the live resource). Pass the surface (page/function/script) and draft_id. Returns null if the draft does not exist in this account.')]
    #[RequiresScope('draft')]
    public function discardDraft(string $surface, int $draft_id): ?array
    {
        $s = DraftService::surfaceByLabel($surface);
        if ($s === null) {
            return null;
        }
        return $this->service()->discardDraft($s, $draft_id);
    }

    /**
     * Commit (publish) a draft live and make it the current version.
     *
     * The ONLY draft tool that changes the live resource. Pages/scripts publish
     * to S3 + invalidate CloudFront; functions regenerate the controller's
     * compiled code base. Requires the 'commit' scope (tokens are draft-only by
     * default). On a failed publish, returns committed:false + error and leaves
     * the draft intact.
     *
     * @param string $surface  page | function | script (from list_drafts).
     * @param int    $draft_id Version id of the draft.
     * @return array{committed:bool, draft_id?:int, parent_id?:int, version_number?:int, site_id?:int, s3key?:string, controller_id?:int, error?:string}|null
     */
    #[McpTool(name: 'commit_draft', description: 'Publish a pending draft live and make it the current version. The only draft action that affects the live resource (pages/scripts publish to S3 + CloudFront; functions regenerate the controller code). Pass the surface (page/function/script) and draft_id. Requires the commit scope. Returns committed:false with an error if the publish fails (the draft is left intact).')]
    #[RequiresScope('commit')]
    public function commitDraft(string $surface, int $draft_id): ?array
    {
        $s = DraftService::surfaceByLabel($surface);
        if ($s === null) {
            return ['committed' => false, 'error' => "Unknown surface '{$surface}'. Use page, function, or script."];
        }
        return $this->service()->commitDraft($s, $draft_id);
    }
}
