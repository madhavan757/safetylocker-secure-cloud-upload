# SecureVault — Setup Instructions

## Files Included
- `upload.html`   → Beautiful frontend UI (drag & drop, progress, encrypted badge)
- `upload.php`    → PHP backend — encrypts file & uploads to AWS S3
- `download.php`  → PHP backend — downloads & decrypts file from AWS S3

---

## Step 1: Install AWS SDK (PHP)

Run this in your project folder:
```bash
composer require aws/aws-sdk-php
```

---

## Step 2: Create AWS S3 Bucket

1. Log in to https://aws.amazon.com/console/
2. Go to **S3** → Create Bucket
   - Bucket name: `your-securevault-bucket`
   - Region: `us-east-1` (or your preferred region)
   - Block Public Access: **ON** (files stay private)
3. Go to **IAM** → Create User
   - Permissions: `AmazonS3FullAccess`
   - Download **Access Key ID** and **Secret Access Key**

---

## Step 3: Configure upload.php & download.php

Open both files and replace:
```php
define('AWS_ACCESS_KEY', 'YOUR_AWS_ACCESS_KEY_ID');       // ← your IAM key
define('AWS_SECRET_KEY', 'YOUR_AWS_SECRET_ACCESS_KEY');   // ← your IAM secret
define('AWS_REGION',     'us-east-1');                    // ← your bucket region
define('AWS_BUCKET',     'your-securevault-bucket');      // ← your bucket name
define('ENCRYPTION_KEY', 'YOUR_32_CHAR_AES_256_KEY!!!'); // ← EXACTLY 32 chars
define('ENCRYPTION_IV',  'YOUR_16_CHAR_IV!!!');           // ← EXACTLY 16 chars
```

> ⚠️ IMPORTANT: Keep ENCRYPTION_KEY and ENCRYPTION_IV secret.
> Store them in environment variables in production, not hardcoded.

---

## Step 4: Deploy to PHP Server

Upload all files to your PHP web server (Apache/Nginx + PHP 7.4+):
```
/your-project/
  ├── upload.html
  ├── upload.php
  ├── download.php
  └── vendor/          ← created by composer
```

Make sure:
- PHP extensions enabled: `openssl`, `fileinfo`, `curl`
- `upload_max_filesize = 50M` in php.ini
- `post_max_size = 55M` in php.ini

---

## How It Works

### Upload Flow:
1. User selects file in browser
2. Frontend sends file to `upload.php` via AJAX
3. PHP reads file bytes
4. **AES-256-CBC encryption** applied to file content
5. Encrypted payload uploaded to **AWS S3** with S3-side encryption (double layer)
6. Presigned download URL returned to browser (valid 7 days)

### Download Flow:
1. User clicks download link
2. `download.php?key=s3-file-key` called
3. PHP fetches encrypted file from S3
4. **AES-256-CBC decryption** applied
5. Original file sent to browser with correct filename & MIME type

---

## Security Features
- AES-256-CBC client-side encryption before S3 storage
- AWS S3 Server-Side Encryption (SSE-AES256) — double encryption layer
- Presigned URLs expire after 7 days
- No plain-text file ever stored on disk or S3
- Input sanitisation & file size validation
- Directory traversal protection in download.php

---

## Azure Alternative

To use **Azure Blob Storage** instead of AWS S3:

```bash
composer require microsoft/azure-storage-blob
```

Replace the S3 upload section in `upload.php` with:
```php
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

$blobClient = BlobRestProxy::createBlobService(
    'DefaultEndpointsProtocol=https;AccountName=YOUR_ACCOUNT;AccountKey=YOUR_KEY'
);

$blobClient->createBlockBlob('your-container', $encryptedName, $finalPayload);
```

---

Built with ❤️ — SecureVault File Encryption System
