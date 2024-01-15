<?php

namespace Kyte\Mvc\Controller;

class KyteShipyardUpdateController extends ModelController
{
    public function hook_init() {
        $this->allowableActions = ['new'];
    }
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}
    // public function hook_preprocess($method, &$r, &$o = null) {}
    // public function hook_response_data($method, $o, &$r = null, &$d = null) {}

    public function new($data)
    {
        if (!in_array('new', $this->allowableActions)) {
            return;
        }

        if (!$this->checkPermissions('new')) {
            throw new \Exception('Permission Denied');
        }
    
        if (!isset($data['current_version'])) {
            throw new \Exception('Missing current_version');
        }

        $credential = new \Kyte\Aws\Credentials(SNS_REGION);
        $sns = new \Kyte\Aws\Sns($credential, SNS_KYTE_SHIPYARD_UPDATE);

        // queue creation of s3 bucket for static web app
        $sns->publish([
            'current_version' => $data['current_version'],
        ]);

        $this->response['data'] = [$data['current_version']];
    }
    
}
