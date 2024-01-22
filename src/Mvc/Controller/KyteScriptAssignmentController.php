<?php

namespace Kyte\Mvc\Controller;

class KyteScriptAssignmentController extends ModelController
{
    public function hook_response_data($method, $o, &$r = null, &$d = null) {
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
