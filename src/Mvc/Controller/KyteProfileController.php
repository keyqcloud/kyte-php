<?php

namespace Kyte\Mvc\Controller;

class KyteProfileController extends ModelController
{
    public function hook_init() {
        $this->model = KyteUser;
    }
    // public function hook_auth() {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        $conditions[] = ['field' => 'id', 'value' => $this->user->id];
        $conditions[] = ['field' => 'kyte_account', 'value' => $this->account->id];
    }

    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if ($method == 'get') {
            $kyteAccount = new \Kyte\Core\ModelObject(KyteAccount);
            if ($kyteAccount->retrieve('id', $r['kyte_account'])) {
                $r['kyte_account'] = $this->getObject($kyteAccount);
            } else {
                $r['kyte_account'] = ['id' => $r['kyte_account']];
            }
        }
    }

    // public function hook_process_get_response(&$r) {}
}
