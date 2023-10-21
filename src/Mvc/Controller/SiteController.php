<?php

namespace Kyte\Mvc\Controller;

class SiteController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'update':
                if (strlen($r['aliasDomain']) > 0) {
                    $domains = [];
                    $assignedDomain = new \Kyte\Core\ModelObject(Domain);
                    if ($assignedDomain->retrieve('assigned', $o->cfDistributionId)) {
                        $domains[] = $assignedDomain->domainName;
                        // get any SANs
                        $sans = new \Kyte\Core\Model(SubjectAlternativeName);
                        $sans->retrieve('domain', $assignedDomain->id);
                        foreach ($sans->objects as $san) {
                            $domains[] = $san->name;
                        }
                    }
                    $matched = false;
                    // Remove slashes and protocols from the domain
                    $r['aliasDomain'] = preg_replace('#^https?://#', '', $r['aliasDomain']);

                    // Validate domain format
                    $pattern = '/^(\*\.)?([a-z0-9-]+\.)*[a-z0-9-]+$/i';
                    $validFormat = preg_match($pattern, $r['aliasDomain']);
                    if (!$validFormat) {
                        throw new \Exception('Alias is not a valid domain format.');
                    }

                    // check if there is a match
                    foreach ($domains as $domain) {
                        if (fnmatch($domain, $r['aliasDomain'])) {
                            $matched = true;
                            break;
                        }
                    }

                    if (!$matched) {
                        throw new \Exception('Alias domain does not match the assigned SSL domain or subject alternative name.');
                    }
                }
                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $d['application'])) {
                    $o->delete();
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                // validate provided region
                $validS3Regions = [
                    "us-east-1", "us-east-2", "us-west-1", "us-west-2",
                    "ap-south-1", "ap-northeast-1", "ap-northeast-2", "ap-southeast-1", "ap-southeast-2",
                    "ca-central-1", "eu-central-1", "eu-north-1", "eu-west-1", "eu-west-2", "eu-west-3",
                    "sa-east-1", "cn-north-1", "cn-northwest-1"
                ];
                
                $region = $r['region'];
                
                // if user provided region string is not valid then throw an exception
                if (!in_array($region, $validS3Regions)) {
                    $o->delete();
                    throw new \Exception("Unknown region $region. Please check to make sure you specified a valid region for AWS S3.");
                }

                // get AWS credential
                $credentials = new \Kyte\Aws\Credentials($region, $app->aws_public_key, $app->aws_private_key);

                // create s3 bucket for site data
                $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $r['name']).'-'.$app->identifier.'-'.time());
                $mediaBucketName = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $r['name']).'-static-assets-'.$app->identifier.'-'.time());
                $o->save([
                    'region'        => $region,
                    's3BucketName'  => $bucketName,
                    's3MediaBucketName'  => $mediaBucketName,
                ]);
                $s3 = new \Kyte\Aws\S3($credentials, $bucketName);
                $medias3 = new \Kyte\Aws\S3($credentials, $mediaBucketName);
                try {
                    $medias3->createBucket();
                    usleep(100000);
                    // remove public access block
                    $medias3->deletePublicAccessBlock();
                    usleep(100000);
                    // enable public access policy (GET)
                    $medias3->enablePublicAccess();
                    usleep(100000);

                    // setup web
                    $s3->createBucket();
                    usleep(100000);
                    $s3->deletePublicAccessBlock();
                    usleep(100000);
                    $s3->enablePublicAccess();
                    usleep(100000);
                    $s3->createWebsite();

                    usleep(15000000); // wait 15 sec before attempting cors

                    // enable cors for upload
                    $medias3->enableCors([
                        [
                            'AllowedHeaders'    =>  ['*'],
                            'AllowedMethods'    =>  ['GET','POST'],
                            'AllowedOrigins'    =>  ['*'],
                        ]
                    ]);
                } catch(\Exception $e) {
                    $o->delete();
                    throw $e;
                }

                // create distribution for website
                $cf = new \Kyte\Aws\CloudFront($credentials);
                $cf->addOrigin(
                    $bucketName.'.s3-website-'.$region.'.amazonaws.com',
                    $bucketName
                );
                $cf->create();
                $o->save([
                    'cfDistributionId'        => $cf->Id,
                    'cfDomain'                => $cf->domainName,
                ]);

                // create distribution for static assets
                $cf = new \Kyte\Aws\CloudFront($credentials);
                $cf->addOrigin(
                    $mediaBucketName.'.s3.amazonaws.com',
                    $mediaBucketName
                );
                $cf->create();
                $o->save([
                    'cfMediaDistributionId'        => $cf->Id,
                    'cfMediaDomain'                => $cf->domainName,
                ]);

                // update return data
                $r['region'] = $region;
                $r['s3BucketName'] = $bucketName;
                $r['cfDistributionId'] = $cf->Id;
                $r['cfDomain'] = $cf->domainName;

                break;
            case 'delete':
                // // get AWS credentials
                // $credentials = new \Kyte\Aws\Credentials('us-east-1');

                // // disable distribution
                // $cf = new \Kyte\Aws\CloudFront($credentials, $o->cfDistribution);
                // $cf->disable();

                // delete distribution
                // $cf->delete();

                // // delete acm certificate
                // $acm = new \Kyte\Aws\Acm($credentials, $o->AcmArn);
                // $acm->delete();

                // delete s3 bucket
                // $s3 = new \Kyte\Aws\S3($credentials, $o->s3bucket, 'public');
                // $s3->emptyBucket(); <- create method
                // $s3->deleteBucket();

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
