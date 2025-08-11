<?php
namespace Kyte\Mvc\Controller;

class KyteLibraryAssignmentController extends ModelController
{
    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if ($method == 'delete') {
            if ($o->include_all == 1) {
                throw new \Exception("Cannot delete assignment for globally included libraries. This library is set to include on all pages. To remove it, either disable global inclusion in Site → Libraries or delete the library entirely.");
            }
        } else {
            if (isset($r['page'], $r['page']['header'], $r['page']['footer'])) {
                $r['page']['header'] = null;
                $r['page']['footer'] = null;
            }
        }
    }
}