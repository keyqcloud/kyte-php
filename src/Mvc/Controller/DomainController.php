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
                if (isset($result['Certificate']['DomainValidationOptions'])) {
                    $r['dns_validation'] = $result['Certificate']['DomainValidationOptions'];
                }

                break;
        }
    }

    public function update($field, $value, $data)
    {
        $response = [];
        
        try {
            if (!in_array('update', $this->allowableActions)) {
                return;
            }
    
            if (!$this->checkPermissions('update')) {
                throw new \Exception('Permission Denied');
            }

            if ($field === null || $value === null) throw new \Exception("Field and Values params not set");

            $obj = new \Kyte\Core\ModelObject($this->model);
            if ($obj->retrieve($field, $value, $conditions, null, $all)) {
                $ret = $this->getObject($obj);

                // keep region as us-east-1 for compatibility with CF
                $credential = new \Kyte\Aws\Credentials('us-east-1');
                $cf = new \Kyte\Aws\CloudFront($credentials, $ret['site']['cfDistributionId']);

                if (isset($data['assigned'])) {
                    // create acm client
                    $acm = new \Kyte\Aws\Acm($credential);

                    // check if certificate has been issued
                    $acm_result = $acm->describe($r['certificateArn']);
                    if ($acm_result['Certificate']['Status'] != 'ISSUED') {
                        throw new \Exception("Unable to assign domain and SSL to site because the SSL certificate has not been issued. Please complete the domain verification");
                    }

                    // assign alias
                    $aliases = [];
                    foreach($acm_result['Certificate']['DomainValidationOptions'] as $san) {
                        $aliases[] = $san['ValidationDomain'];
                    }
                    $cf->setAliases($aliases, $obj->certificateArn);

                } else {
                    // unasigned alias
                    $cf->removeAliases();
                }
                //
                $ret['assigned'] = $data['assigned'];
                $obj->save([
                    'assigned' => $data['assigned'],
                ]);
                $response[] = $ret;
            } else {
                if ($this->failOnNull) {
                    throw new \Exception($this->exceptionMessages['update']['failOnNull']);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    // public function hook_process_get_response(&$r) {}
}
