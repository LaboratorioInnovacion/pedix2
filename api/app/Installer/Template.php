<?php declare(strict_types=1);
namespace VO\Installer;

use VO\Support\Template as SharedTemplate;

final class Template
{
    private SharedTemplate $template;

    public function __construct() { $this->template = new SharedTemplate(__DIR__ . '/templates'); }
    public static function e(mixed $v): string { return SharedTemplate::e($v); }
    public function render(string $view, array $data = []): string { return $this->template->render($view, $data); }
}
