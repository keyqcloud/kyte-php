<?php

namespace Kyte\Util;

class IMDS {
    private static function fetchToken() {
        $token_url = 'http://169.254.169.254/latest/api/token';
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-aws-ec2-metadata-token-ttl-seconds: 60']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        $token = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("Failed to retrieve token for IMDSv2");
        }

        return $token;
    }

    private static function fetchData($url, $token = null) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($token) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-aws-ec2-metadata-token: ' . $token]);
        }

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        return $data;
    }

    public static function fetchMetadataUsingIMDSv2($endpoint) {
        try {
            $token = self::fetchToken();
        } catch (\Exception $e) {
            return false;
        }

        $url = 'http://169.254.169.254/latest/meta-data/' . $endpoint;
        return self::fetchData($url, $token);
    }

    public static function fetchMetadataUsingIMDSv1($endpoint) {
        $url = 'http://169.254.169.254/latest/meta-data/' . $endpoint;
        return self::fetchData($url);
    }

    public static function fetchMetadata($metadataItems = []) {
        if (empty($metadataItems)) {
            $metadataItems = [
                'instance-id',
                'instance-type',
                'ami-id',
                'local-ipv4',
                'placement/availability-zone'
            ];
        }

        $metadata = [];
        foreach ($metadataItems as $item) {
            $data = self::fetchMetadataUsingIMDSv2($item);
            if ($data === false) {
                // Fall back to IMDSv1 if necessary
                $data = self::fetchMetadataUsingIMDSv1($item);
            }
            $metadata[$item] = $data ?: 'Unable to fetch';
        }
        return $metadata;
    }
}
