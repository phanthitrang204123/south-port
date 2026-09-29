<?php

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

$targetOrgSubstrings = [
    'at&t',
];

$targetAsns = [
    7018,   // AT&T Enterprises / AT&T Services - ATT-INTERNET4 (residential/business wireline)
    20057,  // AT&T Mobility LLC
    2386,   // AT&T Enterprises - INS-AS
    6389,   // AT&T Enterprises - BellSouth legacy (Southeast US wireline)
    13965,  // AT&T Enterprises - SBCIS-FYTVAR
];

$attUrl = getenv('ATT_REDIRECT_URL') ?: '';
$defaultUrl = getenv('DEFAULT_REDIRECT_URL') ?: '';



$apiToken = getenv('IPINFO_TOKEN');

$debug = getenv('IPROUTER_DEBUG') === '1';


$appEnv = strtolower(getenv('APP_ENV') ?: 'production');

$testIp = null;

if (in_array($appEnv, ['local', 'staging'], true)) {
    $testIp = getenv('IPROUTER_TEST_IP') ?: null;
}

$disableGeoLite = getenv('IPROUTER_DISABLE_GEOLITE') === '1';

if (
    !filter_var($attUrl, FILTER_VALIDATE_URL) ||
    !filter_var($defaultUrl, FILTER_VALIDATE_URL)
) {
    http_response_code(500);
    exit('Redirect URLs are not configured correctly.');
}
/*
|--------------------------------------------------------------------------
| Get visitor IP
|--------------------------------------------------------------------------
*/

if ($testIp !== null) {
    $ip = trim($testIp);
} else {
    // DigitalOcean App Platform client IP.
    $ip = $_SERVER['HTTP_DO_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

// Defensive handling for comma-separated IP values.
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}

if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    if ($debug) {
        error_log(
            "ISP-router: invalid/missing IP '{$ip}', sending default"
        );
    }

    header("Location: {$defaultUrl}", true, 302);
    exit;
}


/*
|--------------------------------------------------------------------------
| helpers
|--------------------------------------------------------------------------
*/
function isTargetAsn($asn, array $targetAsns): bool
{
    if ($asn === null || $asn === '') {
        return false;
    }

    $digits = preg_replace('/[^0-9]/', '', (string) $asn);

    if ($digits === '') {
        return false;
    }

    return in_array((int) $digits, $targetAsns, true);
}

function isTargetOrg($org, array $targetOrgSubstrings): bool
{
    if ($org === null || $org === '') {
        return false;
    }

    $orgLower = strtolower($org);

    foreach ($targetOrgSubstrings as $needle) {
        if (strpos($orgLower, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function isTarget($asn, $org, array $targetAsns, array $targetOrgSubstrings): bool
{
    // Org-name match is primary: it stays current automatically as
    // MaxMind/IPinfo update their ASN-to-organization mapping.
    if (isTargetOrg($org, $targetOrgSubstrings)) {
        return true;
    }

    // ASN list is the override/fallback for known edge cases.
    return isTargetAsn($asn, $targetAsns);
}


/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/
$isTarget = false;
$lookupSuccessful = false;
$lookupSource = 'none';

$asn = null;
$org = '';


/*
|--------------------------------------------------------------------------
| GeoLite2 lookup
|--------------------------------------------------------------------------
*/
$autoload = __DIR__ . '/vendor/autoload.php';
$database = __DIR__ . '/data/GeoLite2-ASN.mmdb';

if (!$disableGeoLite && file_exists($autoload) && file_exists($database)) {
    try {
        require_once $autoload;

        if (class_exists('\GeoIp2\Database\Reader')) {

            $reader = new \GeoIp2\Database\Reader($database);

            $record = $reader->asn($ip);

            $asn = $record->autonomousSystemNumber ?? null;
            $org = $record->autonomousSystemOrganization ?? '';

            // Only mark successful when an ASN was actually returned.
            if ($asn !== null) {

                $lookupSuccessful = true;
                $lookupSource = 'GeoLite2';

                $isTarget = isTarget($asn, $org, $targetAsns, $targetOrgSubstrings);

                if ($debug) {
                    error_log(
                        "ISP-router [GeoLite2]: IP={$ip}, ASN={$asn}, Org={$org}, match=" .
                        ($isTarget ? 'yes' : 'no')
                    );
                }
            }
        }
    } catch (\Throwable $e) {

        if ($debug) {
            error_log("ISP-router: GeoLite2 lookup failed: " . $e->getMessage());
        }
    }
}


/*
|--------------------------------------------------------------------------
| IPinfo fallback
|--------------------------------------------------------------------------
*/
if (!$lookupSuccessful && $apiToken) {
    try {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is not available');
        }

        // IPinfo Lite. Response: {"asn":"AS7018","as_name":"AT&T Services, Inc.", ...}
        $url = 'https://api.ipinfo.io/lite/' . rawurlencode($ip) . '?token=' . rawurlencode($apiToken);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {

            $data = json_decode($response, true);

            if (is_array($data) && !empty($data['asn'])) {

                $asn = $data['asn'];
                $org = $data['as_name'] ?? '';

                $lookupSuccessful = true;
                $lookupSource = 'IPinfo';

                $isTarget = isTarget($asn, $org, $targetAsns, $targetOrgSubstrings);

                if ($debug) {
                    error_log(
                        "ISP-router [IPinfo]: IP={$ip}, ASN={$asn}, Org={$org}, match=" .
                        ($isTarget ? 'yes' : 'no')
                    );
                }
            }

        } elseif ($debug) {
            error_log("ISP-router: IPinfo failed. HTTP={$httpCode}, Error={$curlError}");
        }

    } catch (\Throwable $e) {

        if ($debug) {
            error_log("ISP-router: IPinfo lookup failed: " . $e->getMessage());
        }
    }
}


/*
|--------------------------------------------------------------------------
| redirects
|--------------------------------------------------------------------------
*/
if (!$lookupSuccessful && $debug) {
    error_log("ISP-router: no ISP/ASN lookup succeeded for IP={$ip}; sending default");
}

if ($isTarget) {

    if ($debug) {
        error_log("ISP-router: TARGET detected via {$lookupSource}; redirecting to AT&T URL");
    }

    header("Location: {$attUrl}", true, 302);
    exit;
}

if ($debug) {
    error_log("ISP-router: target not detected; redirecting to default URL");
}

header("Location: {$defaultUrl}", true, 302);
exit;