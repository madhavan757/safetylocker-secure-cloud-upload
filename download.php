<?php
// ============================================================
//  download.php — Decrypt & Download File from AWS S3
//  Usage: download.php?key=encrypted-uploads/2025/01/01/filename.enc
// ============================================================

require_once __DIR__ . '/vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// ── Same config as upload.php ──
define('AWS_ACCESS_KEY', 'YOUR_AWS_ACCESS_KEY_ID');
define('AWS_SECRET_KEY', 'YOUR_AWS_SECRET_ACCESS_KEY');
define('AWS_REGION',     'us-east-1');
define('AWS_BUCKET',     'your-securevault-bucket');
define('ENCRYPTION_KEY', 'YOUR_32_CHAR_AES_256_SECRET_KEY!!');
define('ENCRYPTION_IV',  'YOUR_16_CHAR_IV!!');

$fileKey = $_GET['key'] ?? '';

if (empty($fileKey)) {
    http_response_code(400);
    die('Missing file key.');
}

// Sanitise key (prevent directory traversal)
$fileKey = ltrim(preg_replace('/\.\.\/|\.\.\\\\/', '', $fileKey), '/');

try {
    $s3 = new S3Client([
        'version'     => 'latest',
        'region'      => AWS_REGION,
        'credentials' => [
            'key'    => AWS_ACCESS_KEY,
            'secret' => AWS_SECRET_KEY,
        ],
    ]);

    // ── GET FILE FROM S3 ──
    $result = $s3->getObject([
        'Bucket' => AWS_BUCKET,
        'Key'    => $fileKey,
    ]);

    $rawContent = (string) $result['Body'];

    // ── SPLIT HEADER FROM PAYLOAD ──
    $separator = "\n---ENCRYPTED_PAYLOAD---\n";
    $sepPos = strpos($rawContent, $separator);

    if ($sepPos === false) {
        http_response_code(500);
        die('Invalid encrypted file format.');
    }

    $metaJson        = substr($rawContent, 0, $sepPos);
    $encryptedPayload= substr($rawContent, $sepPos + strlen($separator));
    $meta            = json_decode($metaJson, true);

    // ── DECRYPT ──
    $decryptedContent = openssl_decrypt(
        $encryptedPayload,
        'AES-256-CBC',
        ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        ENCRYPTION_IV
    );

    if ($decryptedContent === false) {
        http_response_code(500);
        die('Decryption failed. Invalid key or corrupted file.');
    }

    // ── SEND FILE TO BROWSER ──
    $originalName = $meta['original_name'] ?? 'downloaded_file';
    $mimeType     = $meta['mime_type']     ?? 'application/octet-stream';

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"');
    header('Content-Length: ' . strlen($decryptedContent));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $decryptedContent;
    exit;

} catch (AwsException $e) {
    http_response_code(500);
    die('AWS Error: ' . $e->getAwsErrorMessage());
} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . $e->getMessage());
}
