<?php
declare(strict_types=1);

/*
 * Minimal Cloudinary client (no Composer needed): signed upload + destroy.
 * Configure with CLOUDINARY_URL=cloudinary://<api_key>:<api_secret>@<cloud_name>
 * (copy it from the Cloudinary dashboard), or the three separate variables.
 */

function cloudinary_config(): ?array
{
    $url = env('CLOUDINARY_URL');
    if ($url && preg_match('#^cloudinary://([^:]+):([^@]+)@(.+)$#', $url, $m)) {
        return ['api_key' => $m[1], 'api_secret' => $m[2], 'cloud_name' => $m[3]];
    }
    $key = env('CLOUDINARY_API_KEY');
    $secret = env('CLOUDINARY_API_SECRET');
    $cloud = env('CLOUDINARY_CLOUD_NAME');
    return ($key && $secret && $cloud) ? ['api_key' => $key, 'api_secret' => $secret, 'cloud_name' => $cloud] : null;
}

/**
 * Cloudinary signature: sort the parameters by name, join as name=value with "&",
 * append the API secret, SHA-1. file, cloud_name, resource_type and api_key are never signed.
 */
function cloudinary_sign(array $params, string $apiSecret): string
{
    $params = array_diff_key($params, array_flip(['file', 'cloud_name', 'resource_type', 'api_key']));
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
    ksort($params);
    $pairs = [];
    foreach ($params as $name => $value) {
        $pairs[] = $name . '=' . (is_array($value) ? implode(',', $value) : $value);
    }
    return sha1(implode('&', $pairs) . $apiSecret);
}

/** POST a signed request to the Cloudinary image API; returns the decoded response. */
function cloudinary_call(string $action, array $params, ?CURLFile $file = null): array
{
    $cfg = cloudinary_config();
    if (!$cfg) {
        throw new RuntimeException('Cloudinary is not configured');
    }
    $params['timestamp'] = time();
    $fields = $params + [
        'api_key' => $cfg['api_key'],
        'signature' => cloudinary_sign($params, $cfg['api_secret']),
    ];
    if ($file) {
        $fields['file'] = $file;
    }

    $base = rtrim(env('CLOUDINARY_API_BASE', 'https://api.cloudinary.com/v1_1'), '/');
    $ch = curl_init("$base/{$cfg['cloud_name']}/image/$action");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    // Windows/XAMPP PHP often has no CA bundle configured; point curl at one we can find
    if (!ini_get('curl.cainfo') && ($ca = ca_bundle())) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = is_string($body) ? json_decode($body, true) : null;
    if ($body === false || !is_array($data) || $status >= 400 || isset($data['error'])) {
        $reason = $data['error']['message'] ?? ($curlError ?: "HTTP $status");
        throw new RuntimeException("Cloudinary $action failed: $reason");
    }
    return $data;
}

/** Uploads a local image file. Returns ['url' => https URL, 'public_id' => id]. */
function cloudinary_upload(string $path, string $mime): array
{
    $res = cloudinary_call(
        'upload',
        ['folder' => env('CLOUDINARY_FOLDER', 'moneymentor/articles')],
        new CURLFile($path, $mime, 'image')
    );
    return ['url' => $res['secure_url'], 'public_id' => $res['public_id']];
}

/** Deletes an image. Failures are logged, not fatal (the article is already gone). */
function cloudinary_destroy(string $publicId): void
{
    try {
        cloudinary_call('destroy', ['public_id' => $publicId, 'invalidate' => 'true']);
    } catch (Throwable $e) {
        error_log('[moneymentor] ' . $e->getMessage() . " (public_id $publicId)");
    }
}
