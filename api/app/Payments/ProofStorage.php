<?php declare(strict_types=1);
namespace VO\Payments;

use RuntimeException;

final class ProofStorage
{
    private const MAX_BYTES = 5242880;
    private const TYPES = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf'];

    public function __construct(private string $storageRoot) {}

    public function store(array $file, int $businessId, string $orderNumber): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('UPLOAD_FAILED');
        $size = (int)($file['size'] ?? 0); $tmp = (string)($file['tmp_name'] ?? '');
        if ($size < 1 || $size > self::MAX_BYTES || !is_file($tmp)) throw new RuntimeException('INVALID_PROOF_SIZE');
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(self::TYPES[$ext]) || self::TYPES[$ext] !== $mime) throw new RuntimeException('INVALID_PROOF_TYPE');
        $folder = 'proofs/' . $businessId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $orderNumber);
        $directory = rtrim($this->storageRoot, '/\\') . '/' . $folder;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('PROOF_STORAGE_FAILED');
        $relative = $folder . '/' . bin2hex(random_bytes(32)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $target = rtrim($this->storageRoot, '/\\') . '/' . $relative;
        if (!move_uploaded_file($tmp, $target)) throw new RuntimeException('PROOF_STORAGE_FAILED');
        @chmod($target, 0600);
        return str_replace('\\', '/', $relative);
    }

    public function absolute(string $relative): ?string
    {
        $root = realpath($this->storageRoot); $file = realpath(rtrim($this->storageRoot, '/\\') . '/' . ltrim($relative, '/\\'));
        return $root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }
}
