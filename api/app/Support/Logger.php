<?php
declare(strict_types=1);
namespace VO\Support;
final class Logger
{
    public function __construct(private string $file) {}
    public function error(string $message, array $context = []): void
    {
        if (!is_dir(dirname($this->file))) mkdir(dirname($this->file), 0775, true);
        $safe = array_intersect_key($context, array_flip(['request_id', 'method', 'path', 'exception']));
        file_put_contents($this->file, json_encode(['level' => 'error', 'message' => $message, 'context' => $safe, 'at' => gmdate('c')], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}
