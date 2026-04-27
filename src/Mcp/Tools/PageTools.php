<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Site and page read tools.
 *
 * Sites are app-scoped containers; pages live under sites. Account
 * scoping is re-asserted at every level of the chain — `list_pages`
 * has to verify both that the site belongs to the token's account
 * AND that each returned page does too. The deeper FK chain matters
 * here: forgetting one link would let a token holder enumerate pages
 * across accounts by guessing site_ids.
 *
 * Page content (html / stylesheet / javascript) does NOT live on
 * KytePage directly — it lives in KytePageVersionContent, joined via
 * KytePageVersion. There is no "live code" field; "current" content is
 * the version flagged is_current=1 for the page. read_page handles the
 * default-to-current path and the explicit-version-number path
 * symmetrically. If a page has no current version yet (rare — happens
 * for freshly-created pages before first save), content fields come
 * back as empty strings rather than null, so the response shape stays
 * stable for callers.
 *
 * Site infra fields (s3BucketName, cfDistributionId, etc.) are
 * intentionally excluded from list_sites output. They're deployment
 * details, not user-facing site attributes — surfacing them broadens
 * the leak surface for a token without obvious benefit to a developer
 * inspecting an app from Claude.
 */
final class PageTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * List sites in a Kyte application.
     *
     * Returns user-facing site metadata. AWS infrastructure fields
     * (s3BucketName, cfDistributionId, aliasDomain, etc.) are omitted
     * — see class docblock for rationale.
     *
     * @param int $application_id Application id from list_applications.
     * @return array{sites: array<int, array{id:int, name:string, status:string, region:?string, default_lang:?string, description:?string}>}
     */
    #[McpTool(name: 'list_sites', description: 'List sites in a Kyte application.')]
    #[RequiresScope('read')]
    public function listSites(int $application_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($application_id, $accountId)) {
            return ['sites' => []];
        }

        $model = new \Kyte\Core\Model(\KyteSite);
        $model->retrieve('application', $application_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($model->objects as $site) {
            $out[] = [
                'id'           => (int)$site->id,
                'name'         => (string)($site->name ?? ''),
                'status'       => (string)($site->status ?? ''),
                'region'       => $site->region !== null ? (string)$site->region : null,
                'default_lang' => $site->default_lang !== null ? (string)$site->default_lang : null,
                'description'  => $site->description !== null ? (string)$site->description : null,
            ];
        }
        return ['sites' => $out];
    }

    /**
     * List pages on a site.
     *
     * @param int $site_id Site id from list_sites.
     * @return array{pages: array<int, array{id:int, title:string, page_type:string, state:int, lang:?string, sitemap_include:bool}>}
     */
    #[McpTool(name: 'list_pages', description: 'List pages on a Kyte site. Returns metadata only — call read_page for HTML/CSS/JS content.')]
    #[RequiresScope('read')]
    public function listPages(int $site_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['pages' => []];
        }

        $model = new \Kyte\Core\Model(\KytePage);
        $model->retrieve('site', $site_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($model->objects as $page) {
            $out[] = [
                'id'              => (int)$page->id,
                'title'           => (string)($page->title ?? ''),
                'page_type'       => (string)($page->page_type ?? ''),
                'state'           => (int)$page->state,
                'lang'            => $page->lang !== null ? (string)$page->lang : null,
                'sitemap_include' => (int)$page->sitemap_include === 1,
            ];
        }
        return ['pages' => $out];
    }

    /**
     * Read a page's full content (html, stylesheet, javascript), optionally at
     * a specific version.
     *
     * Without `version_number`, returns the version flagged is_current=1.
     * With `version_number`, returns that historical snapshot. Returns
     * null when the page (or requested version) doesn't exist or
     * belongs to another account. Returns the page with empty content
     * when the page exists but has no current version yet.
     *
     * @param int      $page_id        KytePage id from list_pages.
     * @param int|null $version_number Optional KytePageVersion.version_number.
     * @return array{id:int, title:string, description:?string, page_type:string, state:int, site:?int, html:string, stylesheet:string, javascript:string, version:?int, version_type:?string}|null
     */
    #[McpTool(name: 'read_page', description: 'Read a page including its HTML, stylesheet, and JavaScript. Pass version_number to retrieve a specific historical snapshot, or omit for the current published content.')]
    #[RequiresScope('read')]
    public function readPage(int $page_id, ?int $version_number = null): ?array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return null;
        }

        $page = new \Kyte\Core\ModelObject(\KytePage);
        if (!$page->retrieve('id', $page_id) || (int)$page->kyte_account !== $accountId) {
            return null;
        }

        $base = [
            'id'           => (int)$page->id,
            'title'        => (string)($page->title ?? ''),
            'description'  => $page->description !== null ? (string)$page->description : null,
            'page_type'    => (string)($page->page_type ?? ''),
            'state'        => (int)$page->state,
            'site'         => $page->site !== null ? (int)$page->site : null,
            'version'      => null,
            'version_type' => null,
        ];

        $version = new \Kyte\Core\ModelObject(\KytePageVersion);
        $found = false;
        if ($version_number === null) {
            $found = $version->retrieve('page', $page_id, [
                ['field' => 'is_current',   'value' => 1],
                ['field' => 'kyte_account', 'value' => $accountId],
            ]);
        } else {
            $found = $version->retrieve('page', $page_id, [
                ['field' => 'version_number', 'value' => $version_number],
                ['field' => 'kyte_account',   'value' => $accountId],
            ]);
            if (!$found) {
                // Versioned read against a missing version is an explicit
                // miss — distinct from "page exists but never saved." Tell
                // the caller "no such version" via null rather than a
                // potentially-misleading empty content response.
                return null;
            }
        }

        if (!$found) {
            // No current version yet (default-version path only). Return
            // the page metadata with empty content so the caller can still
            // tell the page exists.
            return array_merge($base, [
                'html'       => '',
                'stylesheet' => '',
                'javascript' => '',
            ]);
        }

        $content = new \Kyte\Core\ModelObject(\KytePageVersionContent);
        if (!$content->retrieve('content_hash', (string)$version->content_hash)) {
            // Orphaned version (content row missing). Same shape as no-content,
            // but return the version metadata so the caller knows what they
            // pointed at — helps diagnose data corruption rather than
            // silently masking it as "empty page."
            return array_merge($base, [
                'html'         => '',
                'stylesheet'   => '',
                'javascript'   => '',
                'version'      => (int)$version->version_number,
                'version_type' => (string)($version->version_type ?? ''),
            ]);
        }

        return array_merge($base, [
            'html'         => (string)($content->html ?? ''),
            'stylesheet'   => (string)($content->stylesheet ?? ''),
            'javascript'   => (string)($content->javascript ?? ''),
            'version'      => (int)$version->version_number,
            'version_type' => (string)($version->version_type ?? ''),
        ]);
    }

    private function accountIdOrZero(): int
    {
        return isset($this->api->account->id) ? (int)$this->api->account->id : 0;
    }

    private function applicationBelongsToAccount(int $applicationId, int $accountId): bool
    {
        $app = new \Kyte\Core\ModelObject(\Application);
        return $app->retrieve('id', $applicationId) && (int)$app->kyte_account === $accountId;
    }

    /**
     * Site-belongs-to-account check. Used by list_pages — the site_id
     * argument is caller-supplied, so re-verify the chain before walking
     * down to pages.
     */
    private function siteBelongsToAccount(int $siteId, int $accountId): bool
    {
        $site = new \Kyte\Core\ModelObject(\KyteSite);
        return $site->retrieve('id', $siteId) && (int)$site->kyte_account === $accountId;
    }
}
