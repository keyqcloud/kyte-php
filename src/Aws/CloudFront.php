<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\CloudFront\CloudFrontClient;

class CloudFront extends Client
{
    private $distributionConfig;

    // distribution configuration properties
    // https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/distribution-overview-required-fields.html
    // ** DistributionConfig
    // **** Required fields
    public $CallerReference;                        // '<string>'
    public $Aliases;                                // ['<string>', ...]
    public $DefaultRootObject;                      // '<string>'
    public $Origins;
    public $OriginGroups;
    // public $CacheBehaviors;
    public $CustomErrorResponses;                   // [ 'ErrorCachingMinTTL' => <integer>, 'ErrorCode' => <integer>, 'ResponseCode' => '<string>', 'ResponsePagePath' => '<string>', ]
    public $Comment;                                // '<string>'
    public $LoggingBucket;                          // '<string>'
    public $LoggingEnabled;                         // boolean
    public $LoggingIncludeCookie;                   // boolean
    public $LoggingPrefix;                          // '<string>'
    public $PriceClass;                             // 'PriceClass_100|PriceClass_200|PriceClass_All'
    public $Enabled;                                // boolean
    public $ViewerCertificateACMCertificateArn;     // '<string>'
    public $ViewerCertificate;                      // '<string>'
    public $ViewerCertificateSource;                // 'cloudfront|iam|acm'
    public $ViewerCertificateCloudFrontDefaultCertificate;  // boolean
    public $ViewerCertificateIAMCertificateId;      // '<string>'
    public $ViewerCertificateMinimumProtocolVersion;        // 'SSLv3|TLSv1|TLSv1_2016|TLSv1.1_2016|TLSv1.2_2018|TLSv1.2_2019'
    public $ViewerCertificateSSLSupportMethod;      // 'sni-only|vip|static-ip's
    public $GeoRestriction;                         // ['<string>', ...]
    public $GeoRestrictionRestrictionType;          // 'blacklist|whitelist|none'
    public $WebACLId;                               // '<string>'
    public $HttpVersion;                            // 'http1.1|http2'
    public $IsIPV6Enabled;                          // boolean
    // ** DefaultCacheBehavior
    public $PathPattern;                            // '<string>'
    public $TargetOriginId;                         // string
    public $ForwardedValuesCookies;                 // 'none|whitelist|all'
    public $ForwardedValuesCookiesWhitelistedNames; // ['<string>', ...]
    public $ForwardedValuesHeaders;                 // ['<string>', ...]
    public $ForwardedValuesQueryString;             // boolean
    public $ForwardedValuesQueryStringCacheKeys;    // ['<string>', ...]
    public $TrustedSignersEnabled;                  // boolean
    public $TrustedSigners;                         // ['<string>', ...]
    public $MinTTL;                                 // integer
    public $AllowedMethods;                         // ['<string>', ...]
    public $AllowedCachedMethods;                   // ['<string>', ...]
    public $SmoothStreaming;                        // boolean
    public $DefaultTTL;                             // integer
    public $MaxTTL;                                 // integer
    public $Compress;                               // boolean
    public $FunctionAssociations;                   // [ 'EventType' => 'viewer-request|viewer-response|origin-request|origin-response', 'FunctionARN' => '<string>', ]
    public $LambdaFunctionAssociations;             // [ 'EventType' => 'viewer-request|viewer-response|origin-request|origin-response', 'IncludeBody' => true || false, 'LambdaFunctionARN' => '<string>', ]
    public $FieldLevelEncryptionId;                 // string
    public $CachePolicyId;                          // string
    public $OriginRequestPolicyId;                  // '<string>'
    public $RealtimeLogConfigArn;                   // '<string>'
    public $TrustedKeyGroupsEnabled;                // boolean
    public $TrustedKeyGroups;                       // ['<string>', ...]
    public $ViewerProtocolPolicy;                   // 'allow-all|https-only|redirect-to-https'

    public function __construct($credentials, $distributionId = null) {
        $this->credentials = $credentials;
        $this->Id = $distributionId;
        $this->client = new CloudFrontClient([
            'credentials'	=> $this->credentials->getCredentials(),
            'version'	=> '2020-05-31',
            'region'	=> $this->credentials->getRegion()
        ]);

        // set default values
        $this->CallerReference = 'kyte'.time();
        $this->Aliases = [];
        $this->DefaultRootObject = '';
        $this->Origins = [];
        $this->OriginGroups = [];
        // public $CacheBehaviors;
        $this->CustomErrorResponses;                   // [ 'ErrorCachingMinTTL' => <integer>, 'ErrorCode' => <integer>, 'ResponseCode' => '<string>', 'ResponsePagePath' => '<string>', ]
        $this->Comment = 'Created by Kyte on '.time();
        $this->LoggingBucket = '';
        $this->LoggingEnabled = false;
        $this->LoggingIncludeCookie = false;
        $this->LoggingPrefix = '';
        $this->PriceClass = "PriceClass_All";
        $this->Enabled = true;
        $this->ViewerCertificateACMCertificateArn = '';
        $this->ViewerCertificateCloudFrontDefaultCertificate = true;  // boolean
        $this->ViewerCertificateIAMCertificateId = '';
        $this->ViewerCertificateMinimumProtocolVersion = 'TLSv1';        // 'SSLv3|TLSv1|TLSv1_2016|TLSv1.1_2016|TLSv1.2_2018|TLSv1.2_2019'
        $this->ViewerCertificateSSLSupportMethod = 'sni-only';      // 'sni-only|vip|static-ip's
        $this->GeoRestriction = [];
        $this->GeoRestrictionRestrictionType = "none";
        $this->WebACLId = '';
        $this->HttpVersion = "http2";
        $this->IsIPV6Enabled = true;
        // ** DefaultCacheBehavior
        $this->PathPattern = '';
        $this->TargetOriginId = '';
        // $this->ForwardedValuesCookies;                 // 'none|whitelist|all'
        // $this->ForwardedValuesCookiesWhitelistedNames; // ['<string>', ...]
        // $this->ForwardedValuesHeaders;                 // ['<string>', ...]
        // $this->ForwardedValuesQueryString;             // boolean
        // $this->ForwardedValuesQueryStringCacheKeys;    // ['<string>', ...]
        $this->TrustedSignersEnabled;                  // boolean
        $this->TrustedSigners;                         // ['<string>', ...]
        // $this->MinTTL = 1;
        $this->AllowedMethods = ['GET', 'HEAD'];
        $this->AllowedCachedMethods = ['GET', 'HEAD'];
        $this->SmoothStreaming = false;
        // $this->DefaultTTL = 86400;
        // $this->MaxTTL = 315360000;
        $this->Compress = true;
        // $this->FunctionAssociations;                   // [ 'EventType' => 'viewer-request|viewer-response|origin-request|origin-response', 'FunctionARN' => '<string>', ]
        // $this->LambdaFunctionAssociations;             // [ 'EventType' => 'viewer-request|viewer-response|origin-request|origin-response', 'IncludeBody' => true || false, 'LambdaFunctionARN' => '<string>', ]
        $this->FieldLevelEncryptionId = '';
        $this->CachePolicyId;                          // string
        $this->OriginRequestPolicyId;                  // '<string>'
        $this->RealtimeLogConfigArn;                   // '<string>'
        $this->TrustedKeyGroupsEnabled;                // boolean
        $this->TrustedKeyGroups;                       // ['<string>', ...]
        $this->ViewerProtocolPolicy = 'redirect-to-https';
    }

    public function create() {
        try {
            // generate CF config
            $this->generateNewConfiguration();
            
            
            $result = $this->client->createDistribution([
                'DistributionConfig' => $this->distributionConfig
            ]);

            $this->Id = $result['Distribution']['Id'];
            $this->Arn = $result['Distribution']['ARN'];
    
            $this->distributionConfig = $result['Distribution']['DistributionConfig'];

            return true;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }
    }

    public function list() {
        try {
            $result = $this->client->listDistributions([]);
            return $result;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }
    }

    public function disable($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $this->distributionConfig['Enabled'] = false;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);

            return true;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }
    }

    public function enable($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $this->distributionConfig['Enabled'] = true;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }
    }

    /** consider using Async methods */
    // https://stackoverflow.com/questions/49127996/unable-to-cloudfront-create-invalidation-aws-php-sdk-3-x
    public function createInvalidation($distributionId = null, $paths = ['/*']) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $result = $this->client->createInvalidation([
                'DistributionId'    => $distributionId,
                'InvalidationBatch' => [
                    'CallerReference'   => time().$distributionId,
                    'Paths'             => [
                        'Items'     => $paths,
                        'Quantity'  => count($paths),
                    ],
                ],
            ]);
        } catch(\Exception $e) {
            throw new \Exception("Unable to create new invalidation");
            return false;
        }

        return true;
    }

    public function setAliases($aliases, $acmArn = '', $distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            if (count($aliases) > 0 || empty($acmArn)) {
                throw new \Exception("Aliases require a valid ACM certificate");
            }

            $this->distributionConfig['Aliases']['Items'] = $aliases;
            $this->distributionConfig['Aliases']['Quantity'] = count($aliases);
            $this->distributionConfig['ViewerCertificate']['ACMCertificateArn'] = $acmArn;
            $this->distributionConfig['ViewerCertificate']['CloudFrontDefaultCertificate'] = true;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }
    }

    // removes Acm certificate and associated Aliases
    public function removeAliases($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $this->distributionConfig['ViewerCertificate']['ACMCertificateArn'] = '';
            $this->distributionConfig['ViewerCertificate']['CloudFrontDefaultCertificate'] = true;
            $this->distributionConfig['Aliases']['Items'] = [];
            $this->distributionConfig['Aliases']['Quantity'] = 0;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }
    }

    public function delete($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $result = $this->client->deleteDistribution([
                'Id' => $distributionId
            ]);

            $this->Id = null;
            $this->Arn = null;

            return $result;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }

        return true;
    }

    public function getConfiguration($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $result = $this->client->getDistribution([
                'Id' => $distributionId
            ]);

            if (isset($result['Distribution']['DistributionConfig']))
            {
                $this->distributionConfig = $result['Distribution']['DistributionConfig'];
                return true;
            } else {
                throw new \Exception("Unable to retrieve configuration for distribution $distributionId");
                return false;
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            return false;
        }
    }

    public function getDomainName() {
        $this->getConfiguration();

        return $this->distributionConfig['DomainName'];
    }

    public function addOrigin(
        // $ConnectionAttempts,            // integer
        // $ConnectionTimeout,             // integer
        // $CustomHeaders,                 // [ 'HeaderName' => '<string>', 'HeaderValue' => '<string>' ]
        // $HTTPPort,                      // integer
        // $HTTPSPort,                     // integer
        // $OriginKeepaliveTimeout,        // integer
        // $OriginProtocolPolicy,          // 'http-only|match-viewer|https-only'
        // $OriginReadTimeout,             // integer
        // $OriginSslProtocols,            // ['<string>', ...]
        $DomainName,
        $Id,
        $OriginPath = '',
        $OriginShieldEnabled = true,
        $OriginShieldRegion = 'us-east-1',
        $s3origin = true
        ) {
        $this->TargetOriginId = $Id;
        $origin = [];
        $origin['DomainName'] = $DomainName;
        $origin['Id'] = $Id;
        $origin['OriginPath'] = $OriginPath;
        $origin['OriginShield'] = [
            'Enabled' => $OriginShieldEnabled, // REQUIRED
            'OriginShieldRegion' => $OriginShieldRegion,
        ];
        if ($s3origin) {
            $origin['S3OriginConfig'] = [
                'OriginAccessIdentity' => $S3OriginAccessIdentity,
            ];
        }

        $this->Origins[] = $origin;
    }

    // public function clearOrigins() {
    //     $this->Origins = [];
    // }

    // public function addOriginGroup(
    //     $FailoverCriteriaStatusCodes,
    //     $Id,                    // '<string>'
    //     $Members                // [ 'OriginId' => '<string>' ]
    // ) {
    //     $this->OriginGroups[] = [
    //         'FailoverCriteria' => [ // REQUIRED
    //             'StatusCodes' => [ // REQUIRED
    //                 'Items' => $FailoverCriteriaStatusCodes, // REQUIRED
    //                 'Quantity' => count($FailoverCriteriaStatusCodes), // REQUIRED
    //             ],
    //         ],
    //         'Id' => $Id, // REQUIRED
    //         'Members' => [ // REQUIRED
    //             'Items' => [ // REQUIRED
    //                 $Members
    //             ],
    //             'Quantity' => count($Members), // REQUIRED
    //         ],
    //     ];
    // }

    // public function clearOriginGroups() {
    //     $this->OriginGroups = [];
    // }

    // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-cloudfront-2020-05-31.html#createdistribution
    //
    // OriginGroup and CacheBehavior have been omitted
    private function generateNewConfiguration() {
        if (count($this->Origins) < 1) {
            throw new \Exception("At least one origin must be specified");
        }

        // initialize array
        $this->distributionConfig = [];

        $this->distributionConfig['CallerReference'] = $this->CallerReference;
        $this->distributionConfig['Comment'] = $this->Comment;
        
        $this->distributionConfig['Enabled'] = $this->Enabled;
        $this->distributionConfig['HttpVersion'] = $this->HttpVersion;
        $this->distributionConfig['IsIPV6Enabled'] = $this->IsIPV6Enabled;

        $this->distributionConfig['Origins']['Items'] = $this->Origins;
        $this->distributionConfig['Origins']['Quantity'] = count($this->Origins);
        
        $this->distributionConfig['PriceClass'] = $this->PriceClass;

        $this->distributionConfig['DefaultRootObject'] = $this->DefaultRootObject;

        $this->distributionConfig['ViewerCertificate'] = [
            'ACMCertificateArn' => $this->ViewerCertificateACMCertificateArn,
            'CloudFrontDefaultCertificate' => $this->ViewerCertificateCloudFrontDefaultCertificate,
            'IAMCertificateId' => $this->ViewerCertificateIAMCertificateId,
            'MinimumProtocolVersion' => $this->ViewerCertificateMinimumProtocolVersion,
            'SSLSupportMethod' => $this->ViewerCertificateSSLSupportMethod,
        ];

        $this->distributionConfig['Aliases']['Items'] = [];
        $this->distributionConfig['Aliases']['Quantity'] = 0;

        // initialize DefaultCacheBehavior
        $this->distributionConfig['DefaultCacheBehavior'] = [];
        $this->distributionConfig['DefaultCacheBehavior']['AllowedMethods'] = [
            'CachedMethods' => [
                'Items' => $this->AllowedCachedMethods,
                'Quantity' => count($this->AllowedCachedMethods),
            ],
            'Items' => $this->AllowedMethods,
            'Quantity' => count($this->AllowedMethods),
        ];
        $this->distributionConfig['DefaultCacheBehavior']['Compress'] = $this->Compress;
        $this->distributionConfig['DefaultCacheBehavior']['TargetOriginId'] = $this->TargetOriginId;
        $this->distributionConfig['DefaultCacheBehavior']['ViewerProtocolPolicy'] = $this->ViewerProtocolPolicy;
        
        // Unused configs below....
            // 'DistributionConfig' => [ // REQUIRED
                
                // 'CustomErrorResponses' => [
                //     'Items' => [
                //         $this->CustomErrorResponses
                //     ],
                //     'Quantity' => count($this->CustomErrorResponses), // REQUIRED
                // ],

                // 'DefaultCacheBehavior' => [ // REQUIRED
                    // 'CachePolicyId' => $this->CachePolicyId,
                    // 'DefaultTTL' => $this->DefaultTTL,
                    // 'FieldLevelEncryptionId' => $this->FieldLevelEncryptionId,
                    // 'ForwardedValues' => [
                    //     'Cookies' => [ // REQUIRED
                    //         'Forward' => $this->ForwardedValuesCookies, // REQUIRED
                    //         'WhitelistedNames' => [
                    //             'Items' => $this->ForwardedValuesCookiesWhitelistedNames,
                    //             'Quantity' => count($this->ForwardedValuesCookiesWhitelistedNames), // REQUIRED
                    //         ],
                    //     ],
                    //     'Headers' => [
                    //         'Items' => $this->ForwardedValuesHeaders,
                    //         'Quantity' => count($this->ForwardedValuesHeaders), // REQUIRED
                    //     ],
                    //     'QueryString' => $this->ForwardedValuesQueryString, // REQUIRED
                    //     'QueryStringCacheKeys' => [
                    //         'Items' => $this->ForwardedValuesQueryStringCacheKeys,
                    //         'Quantity' => count($this->ForwardedValuesQueryStringCacheKeys), // REQUIRED
                    //     ],
                    // ],
                    // 'FunctionAssociations' => [
                    //     'Items' => [
                    //         $this->FunctionAssociations
                    //     ],
                    //     'Quantity' => count($this->FunctionAssociations), // REQUIRED
                    // ],
                    // 'LambdaFunctionAssociations' => [
                    //     'Items' => [
                    //         $this->LambdaFunctionAssociations
                    //     ],
                    //     'Quantity' => count($this->LambdaFunctionAssociations), // REQUIRED
                    // ],
                    // 'MaxTTL' => $this->MaxTTL,
                    // 'MinTTL' => $this->MinTTL,
                    // 'OriginRequestPolicyId' => $this->OriginRequestPolicyId,
                    // 'RealtimeLogConfigArn' => $this->RealtimeLogConfigArn,
                    // 'SmoothStreaming' => $this->SmoothStreaming,
                    // 'TrustedKeyGroups' => [
                    //     'Enabled' => $this->TrustedKeyGroupsEnabled, // REQUIRED
                    //     'Items' => $this->TrustedKeyGroups,
                    //     'Quantity' => count($this->TrustedKeyGroups), // REQUIRED
                    // ],
                    // 'TrustedSigners' => [
                    //     'Enabled' => $this->TrustedSignersEnabled, // REQUIRED
                    //     'Items' => $this->TrustedSigners,
                    //     'Quantity' => count($this->TrustedSigners), // REQUIRED
                    // ],
                // ],                
                // 'Logging' => [
                //     'Bucket' => $this->LoggingBucket, // REQUIRED
                //     'Enabled' => $this->LoggingEnabled, // REQUIRED
                //     'IncludeCookies' => $this->LoggingIncludeCookie, // REQUIRED
                //     'Prefix' => $this->LoggingPrefix, // REQUIRED
                // ],
                // 'OriginGroups' => [
                //     'Items' => [
                //         $this->OriginGroups
                //     ],
                //     'Quantity' => count($this->OriginGroups), // REQUIRED
                // ],
                // 'Restrictions' => [
                //     'GeoRestriction' => [ // REQUIRED
                //         'Items' => $this->GeoRestriction,
                //         'Quantity' => count($this->GeoRestriction), // REQUIRED
                //         'RestrictionType' => $this->GeoRestrictionRestrictionType, // REQUIRED
                //     ],
                // ],
        //         'WebACLId' => $this->WebACLId,
        //     ],
        // ];
    }
}
?>
