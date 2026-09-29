<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

/**
 * @param array<string, mixed> $file One element from $_FILES (e.g. $_FILES['image'])
 * @return string|null Relative web path (e.g. uploads/products/12.jpg) or null if no file / skip
 */
function store_product_image(array $file, int $productId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }

    $maxSize = 2 * 1024 * 1024;
    if (filesize($tmp) > $maxSize) {
        throw new RuntimeException('Image must be under 2 MB.');
    }

    $imgInfo = getimagesize($tmp);
    if ($imgInfo === false) {
        throw new RuntimeException('File is not a valid image.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('Image must be JPEG, PNG, WebP, or GIF.');
    }
    $ext = $map[$mime];

    $dir = PRODUCT_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $basename = 'product_' . $productId . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $basename;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save image.');
    }

    return PRODUCT_UPLOAD_URL . '/' . $basename;
}
