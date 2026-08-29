<?php

declare(strict_types=1);

define('VO_TESTING', true);

require __DIR__ . '/../api/bootstrap/autoload.php';
require __DIR__ . '/../tests/TestCase.php';

final class InstallerTestServer
{
    private $process = null; private int $port; private string $cookie; private array $envKeys = []; private bool $noRedirects;
    public function __construct(private string $root, private array $env = []) { $this->port = self::usablePort(); $this->cookie = tempnam(sys_get_temp_dir(), 'vo_cookie_') ?: ''; $this->noRedirects = isset($env['VO_HTTP_NO_REDIRECTS']); }
    /** Pick a random port that Windows actually allows binding (excluded ranges fail here), and is free. */
    private static function usablePort(): int
    {
        for ($i = 0; $i < 25; $i++) {
            $candidate = random_int(20000, 45000);
            $probe = @stream_socket_server('tcp://127.0.0.1:' . $candidate, $errno, $errstr);
            if ($probe !== false) { fclose($probe); usleep(50000); return $candidate; }
        }
        throw new RuntimeException('No usable local port found for PHP server.');
    }
    public function url(string $path = '/install/'): string { return 'http://127.0.0.1:' . $this->port . $path; }
    public function start(): void
    {
        $router = $this->root . '/router.php';
        $cmd = is_file($router) ? [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->root, $router] : [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->root];
        foreach ($this->env + ['VO_INSTALLER_TESTING' => '1'] as $k => $v) { $this->envKeys[] = $k; putenv($k . '=' . $v); }
        $this->process = proc_open($cmd, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, dirname($this->root));
        if (!is_resource($this->process)) throw new RuntimeException('Could not start PHP server.');
        $deadline = microtime(true) + 5;
        do { $status = @file_get_contents($this->url('/install/index.php'), false, stream_context_create(['http'=>['ignore_errors'=>true]])); if ($status !== false) return; usleep(100000); } while (microtime(true) < $deadline);
        $status = proc_get_status($this->process); $err = isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        $this->stop(); throw new RuntimeException('PHP server did not start. ' . ($status['running'] ? 'still running' : 'exited') . ' ' . $err);
    }
    public function request(string $method, string $path, array $data = []): array
    {
        $headers = ['ignore_errors' => true, 'method' => $method, 'follow_location' => $this->noRedirects ? 0 : 1, 'max_redirects' => $this->noRedirects ? 0 : 20, 'header' => "Cookie: " . $this->cookieHeader() . "\r\n"];
        if ($method === 'POST') { $body = http_build_query($data); $headers['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"; $headers['content'] = $body; }
        $body = file_get_contents($this->url($path), false, stream_context_create(['http' => $headers]));
        $code = 0; foreach ($http_response_header ?? [] as $h) { if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) $code = (int) $m[1]; if (stripos($h, 'Set-Cookie:') === 0) file_put_contents($this->cookie, trim(substr($h, 11)) . "\n", FILE_APPEND); }
        return ['status' => $code, 'body' => $body ?: '', 'headers' => $http_response_header ?? []];
    }
    public function upload(string $path, array $fields, string $field, string $file, string $name): array
    {
        $boundary='----vo'.bin2hex(random_bytes(12)); $body='';
        foreach($fields as $key=>$value)$body.="--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
        $body.="--$boundary\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$name\"\r\nContent-Type: application/octet-stream\r\n\r\n".file_get_contents($file)."\r\n--$boundary--\r\n";
        $options=['ignore_errors'=>true,'method'=>'POST','follow_location'=>$this->noRedirects?0:1,'max_redirects'=>$this->noRedirects?0:20,'header'=>"Cookie: ".$this->cookieHeader()."\r\nContent-Type: multipart/form-data; boundary=$boundary\r\n",'content'=>$body];
        $response=file_get_contents($this->url($path),false,stream_context_create(['http'=>$options])); $code=0;
        foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$header,$m))$code=(int)$m[1];
        return ['status'=>$code,'body'=>$response?:'','headers'=>$http_response_header??[]];
    }
    private function cookieHeader(): string { if (!is_file($this->cookie)) return ''; $pairs = []; foreach (file($this->cookie, FILE_IGNORE_NEW_LINES) ?: [] as $c) { $pair = explode(';', $c, 2)[0]; $name = explode('=', $pair, 2)[0]; $pairs[$name] = $pair; } return implode('; ', array_values($pairs)); }
    public function stop(): void { if (is_resource($this->process)) { $s = proc_get_status($this->process); proc_terminate($this->process); usleep(150000); $s2 = proc_get_status($this->process); if (($s2['running'] ?? false) && ($s['pid'] ?? 0) > 0 && PHP_OS_FAMILY === 'Windows') @exec('taskkill /F /T /PID ' . (int) $s['pid']); proc_close($this->process); } foreach (array_unique($this->envKeys) as $k) putenv($k); if ($this->cookie) @unlink($this->cookie); }
    public function __destruct() { $this->stop(); }
}

$filter = null;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--filter' && isset($argv[$i + 1])) {
        $filter = array_filter(array_map('trim', explode(',', $argv[++$i])));
    }
}

$files = glob(__DIR__ . '/../tests/*Test.php') ?: [];
$failures = 0;
$tests = 0;

foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\' . basename($file, '.php');
    if ($filter !== null && !in_array(basename($file, '.php'), $filter, true)) {
        continue;
    }
    $case = new $class();
    foreach (get_class_methods($case) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $tests++;
        try {
            $case->$method();
            echo 'PASS ' . $class . '::' . $method . PHP_EOL;
        } catch (Throwable $e) {
            $failures++;
            echo 'FAIL ' . $class . '::' . $method . ' - ' . $e->getMessage() . PHP_EOL;
        }
    }
}

echo PHP_EOL . $tests . ' tests, ' . $failures . ' failures' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
