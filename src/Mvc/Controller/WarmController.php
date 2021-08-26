<?php

namespace Kyte\Mvc\Controller;

// The WarmController unpauses serverless databases that have the 'pause' feature enabled.
// Can be called from front end by making a get request to Warm

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
