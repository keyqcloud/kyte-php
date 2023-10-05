<?php

class MediaController extends \Kyte\Mvc\Controller\ModelController
{
    public function hook_init() {
        $this->allowableActions = ['new', 'get','delete'];
    }
    // public function hook_auth() {}

	public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                $filename = preg_replace('/[^A-Za-z0-9_.-]/', '-', $d['filename']);

				$path = date('Y-m-d');
                $key = "{$path}/{$filename}";

				$o->save([
					's3key' => $key,
				]);

                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['site']['application']['id'])) {
                    $o->delete();
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                $dt = new DateTime();
                $exp = new DateTime('+2 hours');
                $dt->setTimezone(new DateTimeZone('UTC'));
                $exp->setTimezone(new DateTimeZone('UTC'));
                $expiration = $exp->format('Y-m-d\TH:i:s\Z');
                $ymd = $dt->format('Ymd');
                $credential = $app->aws_public_key."/".$ymd."/".$r['site']['region']."/s3/aws4_request";
                // $date = $ymd."T000000Z";
                $date = $dt->format('Ymd\THis\Z');

                $policy = base64_encode(json_encode([
                    'expiration'	=> $expiration,
                    'conditions'	=> [
                        ['acl'			=> 'private'],
                        ['bucket'		=> $r['site']['s3MediaBucketName']],
                        ['starts-with', '$key', $path],
                        ["x-amz-credential" => $credential],
                        ["x-amz-algorithm" => "AWS4-HMAC-SHA256"],
                        ["x-amz-date" => $date]
                    ],
                ]));

                // calculate signature
                $hash1 = hash_hmac(
                    'sha256',
                    $ymd,
                    "AWS4".$app->aws_private_key,
                    true
                );
                $hash2 = hash_hmac(
                    'sha256',
                    $r['site']['region'],
                    $hash1,
                    true
                );
                $hash3 = hash_hmac(
                    'sha256',
                    "s3",
                    $hash2,
                    true
                );
                $signingKey = hash_hmac(
                    'sha256',
                    "aws4_request",
                    $hash3,
                    true
                );
                $signature = hash_hmac(
                    'sha256',
                    $policy,
                    $signingKey
                );

                $r['filename'] = $filename;
                $r['s3key'] = $key;;
				// - key
				// - policy
				// - credential
				// - date
				// - siganture
				// - s3endpoint
                $r['policy'] = $policy;
				$r['signature'] = $signature;
				$r['date'] = $date;
				$r['credential'] = $credential;
				$r['key'] = $key;
				$r['s3endpoint'] = 'https://'.$r['site']['s3MediaBucketName'].'.s3.'.$r['site']['region'].'.amazonaws.com';
                break;

			case 'get':
				if ($o->s3key) {
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $r['site']['application']['id'])) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application.");
                    }

                    // keep region as us-east-1 for compatibility with CF
                    $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3MediaBucketName']);
                    $r['download'] = $s3->getObject($o->s3key);
				}
				break;

			case 'delete':
                if ($o->s3key) {
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $d['site']['application']['id'])) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application.");
                    }

                    $d = $this->getObject($o);
                    $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3MediaBucketName']);
                    $s3->unlink($o->s3key);
				}
				break;
            default:
                break;
        }
    }
}

?>