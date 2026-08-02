<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Kyte\Mcp\Util\Bz2Codec;
use Mcp\Capability\Attribute\McpTool;

/**
 * Site script tools (JS/CSS assets).
 *
 * Scripts are site-scoped assets that get bundled into the site's pages.
 * Before this, MCP could only edit an existing script's content
 * (write_script_content) — it could not list, read, or create scripts,
 * which blocked building a frontend from scratch.
 *
 * Account scoping is re-asserted at every level (site belongs to account,
 * script belongs to account) so a token holder can't enumerate another
 * account's scripts by guessing ids. Live content lives on KyteScript.content
 * (bzip2); versions are snapshots in KyteScriptVersion/Content.
 */
final class ScriptTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * List scripts on a site.
     *
     * @param int $site_id Site id (from list_sites).
     * @return array{scripts: array<int, array{id:int, name:string, script_type:string, is_js_module:bool, include_all:bool, state:int}>}
     */
    #[McpTool(name: 'list_scripts', description: 'List scripts (JS/CSS assets) on a Kyte site. Metadata only — call read_script for content.')]
    #[RequiresScope('read')]
    public function listScripts(int $site_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['scripts' => []];
        }

        $model = new \Kyte\Core\Model(\KyteScript);
        $model->retrieve('site', $site_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($model->objects as $s) {
            $out[] = [
                'id'           => (int)$s->id,
                'name'         => (string)($s->name ?? ''),
                'script_type'  => (string)($s->script_type ?? ''),
                'is_js_module' => (int)($s->is_js_module ?? 0) === 1,
                'include_all'  => (int)($s->include_all ?? 0) === 1,
                'state'        => (int)$s->state,
            ];
        }
        return ['scripts' => $out];
    }

    /**
     * Read a script's live content.
     *
     * @param int $script_id KyteScript id (from list_scripts).
     * @return array{id:int, name:string, script_type:string, is_js_module:bool, include_all:bool, state:int, content:string}|null
     */
    #[McpTool(name: 'read_script', description: 'Read a site script including its source content.')]
    #[RequiresScope('read')]
    public function readScript(int $script_id): ?array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return null;
        }

        $s = new \Kyte\Core\ModelObject(\KyteScript);
        if (!$s->retrieve('id', $script_id) || (int)$s->kyte_account !== $accountId) {
            return null;
        }

        return [
            'id'           => (int)$s->id,
            'name'         => (string)($s->name ?? ''),
            'script_type'  => (string)($s->script_type ?? ''),
            'is_js_module' => (int)($s->is_js_module ?? 0) === 1,
            'include_all'  => (int)($s->include_all ?? 0) === 1,
            'state'        => (int)$s->state,
            'content'      => Bz2Codec::decompressIfBz2($s->content),
        ];
    }

    /**
     * Create a script on a site (empty). Add source with write_script_content,
     * then publish with commit_draft. No AWS/S3 happens at create.
     *
     * @param int         $site_id      Site id (from list_sites).
     * @param string      $name         Script name.
     * @param string      $filename     File name for the asset (e.g. app.js, tasks.js)
     *                                   — stored under assets/<script_type>/<filename>.
     * @param string|null $script_type  Asset type: 'js' (default) or 'css'.
     * @param bool        $include_all  Auto-include on every page of the site (default false).
     * @return array{created: bool, script?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'create_script', description: 'Create a site script (JS/CSS asset), empty. Provide a filename like app.js or tasks.js. Add source with write_script_content, then publish with commit_draft. Set include_all to auto-load it on every page.')]
    #[RequiresScope('schema')]
    public function createScript(int $site_id, string $name, string $filename, ?string $script_type = 'js', bool $include_all = false): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['created' => false, 'error' => 'Site not found in this account.'];
        }
        if (trim($name) === '' || trim($filename) === '') {
            return ['created' => false, 'error' => 'name and filename are required.'];
        }
        $type = ($script_type !== null && trim($script_type) !== '') ? strtolower(trim($script_type)) : 'js';
        if (!in_array($type, ['js', 'css'], true)) {
            return ['created' => false, 'error' => "Invalid script_type '{$type}'. Use 'js' or 'css'."];
        }

        // KyteScriptController attributes created_by + the initial version to
        // $api->user, which MCP tokens don't populate (account only). Bind a
        // representative account user for the internal call, restored after.
        $api = $this->api;
        $priorUser = isset($api->user) ? $api->user : null;
        $acctUser = new \Kyte\Core\ModelObject(\KyteUser);
        if (!$acctUser->retrieve('kyte_account', $accountId)) {
            return ['created' => false, 'error' => 'No user is available for this account to attribute the change to.'];
        }
        $api->user = $acctUser;

        $resp = [];
        try {
            $ctrl = new \Kyte\Mvc\Controller\KyteScriptController(\KyteScript, $api, 'm/d/Y H:i:s', $resp, true);
            $ctrl->new([
                'site'         => $site_id,
                'name'         => $name,
                'script_type'  => $type,
                's3key'        => $filename,
                'content'      => '',
                'include_all'  => $include_all ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        } finally {
            $api->user = $priorUser;
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['created' => false, 'error' => 'Script was not created.'];
        }
        return [
            'created' => true,
            'script'  => $this->readScript($newId),
            'note'    => 'Empty script created. Add source with write_script_content, then publish with commit_draft.',
        ];
    }

    /**
     * Delete a site script.
     *
     * @param int $script_id KyteScript id (from list_scripts).
     * @return array{deleted: bool, script_id?: int, error?: string}
     */
    #[McpTool(name: 'delete_script', description: 'Delete a site script and its versions.')]
    #[RequiresScope('schema')]
    public function deleteScript(int $script_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->scriptBelongsToAccount($script_id, $accountId)) {
            return ['deleted' => false, 'error' => 'Script not found in this account.'];
        }

        $api = $this->api;
        $priorUser = isset($api->user) ? $api->user : null;
        $acctUser = new \Kyte\Core\ModelObject(\KyteUser);
        if ($acctUser->retrieve('kyte_account', $accountId)) {
            $api->user = $acctUser;
        }

        $resp = [];
        try {
            $ctrl = new \Kyte\Mvc\Controller\KyteScriptController(\KyteScript, $api, 'm/d/Y H:i:s', $resp, true);
            $ctrl->delete('id', $script_id);
        } catch (\Throwable $e) {
            return ['deleted' => false, 'error' => $e->getMessage()];
        } finally {
            $api->user = $priorUser;
        }
        return ['deleted' => true, 'script_id' => $script_id];
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

    private function scriptBelongsToAccount(int $scriptId, int $accountId): bool
    {
        $s = new \Kyte\Core\ModelObject(\KyteScript);
        return $s->retrieve('id', $scriptId) && (int)$s->kyte_account === $accountId;
    }
}
