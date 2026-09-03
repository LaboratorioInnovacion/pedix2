<?php declare(strict_types=1);
namespace VO\Notifications;

use RuntimeException; use Throwable;

/**
 * Minimal raw-socket SMTP client (spec N5, no Composer): EHLO → optional
 * STARTTLS → AUTH LOGIN → MAIL FROM → RCPT TO → DATA (base64 body, RFC 5321
 * dot-stuffing) → QUIT. ≥5s connect/read timeouts by default; injectable
 * $connect replaces fsockopen in tests. Every Throwable becomes a typed
 * outcome; error text never includes credentials, the host, or message content.
 */
final class SmtpClient implements NotificationTransport
{
    private const READ_BYTES = 1024;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $fromEmail,
        private string $fromName,
        private bool $starttls = true,
        private int $timeoutSec = 5,
        private ?\Closure $connect = null,
    ) {}

    public function send(string $to, string $subject, string $body): array
    {
        try {
            return $this->dialogue($to, $subject, $body);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'smtp send failed: ' . $e->getMessage()];
        }
    }

    /** @return array{ok:bool,error:?string} */
    private function dialogue(string $to, string $subject, string $body): array
    {
        $stream = $this->open();
        try {
            $this->expect($stream, 220, 'greeting');                                   // server greeting
            $ehlo = $this->ehlo($stream);
            if ($this->starttls && stripos($ehlo, 'STARTTLS') !== false) {
                $this->expect($stream, 220, 'starttls', 'STARTTLS');
                if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'error' => 'smtp starttls negotiation failed'];
                }
                $this->ehlo($stream);                                                  // capabilities after TLS
            }
            $this->expect($stream, 334, 'auth login', 'AUTH LOGIN');
            $this->expect($stream, 334, 'auth username', base64_encode($this->username));
            $this->expect($stream, 235, 'auth password', base64_encode($this->password));
            $this->expect($stream, 250, 'mail from', 'MAIL FROM:<' . $this->fromEmail . '>');
            $this->expect($stream, 250, 'rcpt to', 'RCPT TO:<' . $to . '>');
            $this->expect($stream, 354, 'data', 'DATA');
            if (fwrite($stream, $this->message($to, $subject, $body) . ".\r\n") === false) {
                throw new RuntimeException('payload write failed');
            }
            $this->expect($stream, 250, 'message body');
            $this->write($stream, 'QUIT');
            return ['ok' => true, 'error' => null];
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    private function open()
    {
        if ($this->connect !== null) return ($this->connect)();
        $stream = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeoutSec);
        if ($stream === false) throw new RuntimeException('connect failed (' . $errno . ')');
        stream_set_timeout($stream, $this->timeoutSec);
        return $stream;
    }

    /** Sends EHLO with the from-address domain and returns the capability reply. @param resource $stream */
    private function ehlo($stream): string
    {
        $at = strrchr($this->fromEmail, '@');
        $domain = $at === false || $at === '@' ? 'localhost' : substr($at, 1);
        return $this->expect($stream, 250, 'ehlo', 'EHLO ' . ($domain !== '' ? $domain : 'localhost'));
    }

    /**
     * Reads one full SMTP reply (all continuation lines up to "NNN ") and
     * asserts its status code, after optionally sending a command.
     * @param resource $stream
     */
    private function expect($stream, int $code, string $stage, ?string $command = null): string
    {
        if ($command !== null) $this->write($stream, $command);
        $reply = $this->reply($stream, $stage);
        if ((int) substr(ltrim($reply), 0, 3) !== $code) {
            throw new RuntimeException('unexpected reply at ' . $stage . ': ' . $this->firstLine($reply));
        }
        return $reply;
    }

    /** @param resource $stream */
    private function reply($stream, string $stage): string
    {
        $lines = '';
        do {
            $line = fgets($stream, self::READ_BYTES);
            if ($line === false) {
                $meta = stream_get_meta_data($stream);
                throw new RuntimeException($meta['timed_out'] ? 'reply timeout at ' . $stage : 'connection closed at ' . $stage);
            }
            $lines .= $line;
        } while (preg_match('/^\d{3}-/', $line) === 1); // continuation lines end with "-", final with space
        return $lines;
    }

    /** @param resource $stream */
    private function write($stream, string $line): void
    {
        if (fwrite($stream, $line . "\r\n") === false) throw new RuntimeException('write failed');
    }

    /** Headers + base64 body; base64 lines never start with a dot, headers cannot either. */
    private function message(string $to, string $subject, string $body): string
    {
        $headers = 'From: ' . $this->encoded($this->fromName) . ' <' . $this->fromEmail . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $this->encoded($subject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n";
        return preg_replace('/^\./m', '..', $headers . chunk_split(base64_encode($body))) ?? $headers;
    }

    /** RFC 2047 UTF-8 encoded-word for Spanish display names and subjects. */
    private function encoded(string $text): string
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private function firstLine(string $reply): string
    {
        $line = strtok($reply, "\r\n") ?: '';
        return substr(trim($line), 0, 120);
    }
}
