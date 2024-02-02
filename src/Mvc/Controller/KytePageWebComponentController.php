<?php

namespace Kyte\Mvc\Controller;

class KytePageWebComponentController extends ModelController
{
    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if (isset($r['component'])) {
            $r['component']['html'] = '';
            $r['component']['stylesheet'] = '';
        }
        if (isset($r['page'], $r['page']['header'], $r['page']['footer'])) {
            $r['page']['header'] = null;
            $r['page']['footer'] = null;
        }
    }
}
