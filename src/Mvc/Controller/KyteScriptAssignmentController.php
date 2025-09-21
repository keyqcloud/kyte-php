<?php
namespace Kyte\Mvc\Controller;

class KyteScriptAssignmentController extends ModelController
{
    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if ($method == 'delete') {
            if ($o->include_all == 1) {
                throw new \Exception("Cannot delete assignment for globally included script. This script is set to include on all pages. To remove it, either disable global inclusion in Site → Custom Scripts or delete the script entirely.");
            }
        } else {
            if ($method == 'new') {
                $assignments = new \Kyte\Core\Model($this->model);
                $assignments->retrieve('script', $d['library'], false, [['field' => 'page', 'value' => $d['page']], ['field' => 'site', 'value' => $d['site']], ['field' => 'kyte_account', 'value' => $this->account->id]]);
                if ($assignments->count > 0) {
                    throw new \Exception("This custom script is already assigned to the specified page or site.");
                }
            }
            if (isset($r['script'])) {
                $r['script']['content'] = '';
                $r['script']['content_js_obfuscated'] = '';
            }
            if (isset($r['page'], $r['page']['header'], $r['page']['footer'])) {
                $r['page']['header'] = null;
                $r['page']['footer'] = null;
            }
        }
    }
}