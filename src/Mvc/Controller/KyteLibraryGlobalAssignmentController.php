<?php
namespace Kyte\Mvc\Controller;

class KyteLibraryGlobalAssignmentController extends ModelController
{
    public function hook_init() {
        // for global assignments, disallow direct create/update/delete
        $this->allowableActions = ['get'];
    }

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        if (!is_array($conditions)) {
            $conditions = [];
        }
        $conditions[] = ['field' => 'global_scope', 'value' => 1];
    }
}