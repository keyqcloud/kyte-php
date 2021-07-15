<?php

namespace Kyte\Mvc\Controller;

class WarmController extends ModelController
{
    public function hook_init() {
        // make public controller
        $this->requireAuth = false;
        // only allow get
            $this->allowableActions = ['get'];
    }

    // get - retrieve objects from db
    public function get($field, $value) { return [];}
}

?>
