<?php
// ============================================================
//  upload.php — Encrypted File Upload to AWS S3
//  Requires: composer require aws/aws-sdk-php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── AWS CONFIGURATION ── (replace with your credentials)
define('AWS_ACCESS_KEY',    'YOUR_AWS_ACCESS_KEY_ID');
define('AWS_SECRET_KEY',    'YOUR_AWS_SECRET_ACCESS_KEY');
define('AWS_REGION',        'us-east-1');
define('AWS_BUCKET',        'your-securevault-bucket');
define('ENCRYPTION_KEY',    'YOUR_32_CHAR_AES_256_SECRET_KEY!!');  // exactly 32 chars
define('ENCRYPTION_IV',     'YOUR_16_CHAR_IV!!');                   // exactly 16 chars
define('MAX_FILE_SIZE',     50 * 1024 * 1024); // 50MB max

// ── AUTOLOAD ──
require_once __DIR__ . '/vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// ── VALIDATE REQUEST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server max size (php.ini)',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form max size',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder on server',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload',
    ];
    $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonError($uploadErrors[$errCode] ?? 'Unknown upload error');
}

$file     = $_FILES['file'];
$origName = basename($file['name']);
$tmpPath  = $file['tmp_name'];
$fileSize = $file['size'];
$mimeType = mime_content_type($tmpPath);

// ── SIZE CHECK ──
if ($fileSize > MAX_FILE_SIZE) {
    jsonError('File too large. Maximum allowed size is 50MB.');
}

// ── SANITISE FILENAME ──
$safeName    = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
$timestamp   = date('Ymd_His');
$uniqueId    = bin2hex(random_bytes(8));
$storedName  = $timestamp . '_' . $uniqueId . '_' . $safeName;
$encryptedName = $storedName . '.enc';

// ── READ FILE CONTENT ──
$fileContent = file_get_contents($tmpPath);
if ($fileContent === false) {
    jsonError('Failed to read uploaded file.');
}

// ── AES-256-CBC ENCRYPTION ──
$encryptedContent = openssl_encrypt(
    $fileContent,
    'AES-256-CBC',
    ENCRYPTION_KEY,
    OPENSSL_RAW_DATA,
    ENCRYPTION_IV
);

if ($encryptedContent === false) {
    jsonError('Encryption failed: ' . openssl_error_string());
}

// Prepend metadata header (original filename + mime type for decryption reference)
$metaHeader = json_encode([
    'original_name' => $origName,
    'mime_type'     => $mimeType,
    'file_size'     => $fileSize,
    'encrypted_at'  => date('c'),
    'algorithm'     => 'AES-256-CBC',
]) . "\n---ENCRYPTED_PAYLOAD---\n";

$finalPayload = $metaHeader . $encryptedContent;

// ── S3 UPLOAD ──
try {
    $s3 = new S3Client([
        'version'     => 'latest',
        'region'      => AWS_REGION,
        'credentials' => [
            'key'    => AWS_ACCESS_KEY,
            'secret' => AWS_SECRET_KEY,
        ],
    ]);

    $s3Key = 'encrypted-uploads/' . date('Y/m/d/') . $encryptedName;

    $result = $s3->putObject([
        'Bucket'             => AWS_BUCKET,
        'Key'                => $s3Key,
        'Body'               => $finalPayload,
        'ContentType'        => 'application/octet-stream',
        'ServerSideEncryption' => 'AES256',   // S3-side encryption (double layer)
        'Metadata'           => [
            'original-name'  => $origName,
            'encrypted-by'   => 'SecureVault-AES256',
            'upload-date'    => date('c'),
        ],
        'StorageClass'       => 'STANDARD',
    ]);

    // ── GENERATE PRESIGNED DOWNLOAD URL (valid 7 days) ──
    $cmd = $s3->getCommand('GetObject', [
        'Bucket' => AWS_BUCKET,
        'Key'    => $s3Key,
    ]);
    $presignedRequest = $s3->createPresignedRequest($cmd, '+7 days');
    $downloadUrl = (string) $presignedRequest->getUri();

    // ── LOG UPLOAD (optional - save to DB or log file) ──
    $logEntry = [
        'uploaded_at'  => date('c'),
        'original_name'=> $origName,
        's3_key'       => $s3Key,
        'file_size'    => $fileSize,
        'mime_type'    => $mimeType,
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ];
    file_put_contents(__DIR__ . '/upload_log.json',
        json_encode($logEntry) . "\n",
        FILE_APPEND | LOCK_EX
    );

    // ── SUCCESS RESPONSE ──
    jsonSuccess([
        'success'      => true,
        'filename'     => $origName,
        'stored_as'    => $encryptedName,
        'file_key'     => $s3Key,
        'download_url' => $downloadUrl,
        'file_size'    => formatBytes($fileSize),
        'encrypted'    => true,
        'algorithm'    => 'AES-256-CBC + S3 SSE-AES256',
        'message'      => 'File encrypted and uploaded to AWS S3 successfully.',
    ]);

} catch (AwsException $e) {
    jsonError('AWS S3 Error: ' . $e->getAwsErrorMessage());
} catch (Exception $e) {
    jsonError('Server Error: ' . $e->getMessage());
}

// ── HELPERS ──
function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function jsonSuccess($data) {
    http_response_code(200);
    echo json_encode($data);
    exit;
}

function formatBytes($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
