<?php

namespace Kyte\Mvc\Controller;

class DomainController extends ModelController
{
    // public function hook_init() {}

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        // keep region as us-east-1 for compatibility with CF
        $credential = new \Kyte\Aws\Credentials('us-east-1');

        // create acm client
        $acm = new \Kyte\Aws\Acm($credential);

        switch ($method) {
            case 'new':
                $len = count($d['name']);

                $obj = new \Kyte\Core\ModelObject(SubjectAlternativeName);

                for ($i = 0; $i < $len; $i++) {
                    if (!$obj->create([
                        "name" => $d['name'][$i],
                        'domain' => $o->id,
                        'kyte_account' => $this->account->id,
                    ])) {
                        $o->delete();
                        throw new \Exception("Unable to create subject alternative name(s).");
                    }
                }

                // make cert request
                $r['certificateArn'] = $acm->request($r['domainName'], $d['name']);

                // update domain certificate arn
                $o->save([
                    'certificateArn' => $r['certificateArn'],
                ]);

            case 'get':
                $result = $acm->describe($r['certificateArn']);

                $r['status'] = $result['Certificate']['Status'];

                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
