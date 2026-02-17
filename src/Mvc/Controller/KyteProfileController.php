<?php

namespace Kyte\Mvc\Controller;

class KyteProfileController extends ModelController
{
    public function hook_init() {
        $this->model = KyteUser;
    }
    // public function hook_auth() {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        // For update/delete, field and value are required by ModelController.
        // KyteProfile always targets the authenticated user, so default to their ID.
        if ($field === null) $field = 'id';
        if ($value === null) $value = $this->user->id;

        $conditions[] = ['field' => 'id', 'value' => $this->user->id];
        $conditions[] = ['field' => 'kyte_account', 'value' => $this->account->id];
    }

    // public function hook_preprocess($method, &$r, &$o = null) {}

    // public function hook_response_data($method, $o, &$r = null, &$d = null) {}

    // public function hook_process_get_response(&$r) {}
}
