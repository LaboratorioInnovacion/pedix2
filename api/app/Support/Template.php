<?php declare(strict_types=1);
namespace VO\Support;

final class Template
{
    public function __construct(private string $basePath) {}

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function render(string $view, array $vars = []): string
    {
        // Parameter must not be named $data: a template variable named 'data' would
        // collide with it under EXTR_SKIP and silently render empty values.
        extract($vars, EXTR_SKIP);
        ob_start();
        require $this->basePath . '/' . $view . '.php';
        return (string)ob_get_clean();
    }
}
