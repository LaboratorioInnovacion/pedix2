<?php declare(strict_types=1);
namespace VO\Notifications;

/**
 * Notification transport contract (spec N4): every send resolves to a typed
 * outcome and NEVER throws — socket failures, protocol errors, timeouts, and
 * unconfigured/invalid settings all become ['ok' => false, 'error' => <text>].
 * Error text must never contain credentials or endpoint URLs.
 */
interface NotificationTransport
{
    /** @param string $subject Email subject; ignored on subjectless channels (WhatsApp). @return array{ok:bool,error:?string} */
    public function send(string $to, string $subject, string $body): array;
}
