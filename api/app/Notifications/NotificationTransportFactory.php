<?php declare(strict_types=1);
namespace VO\Notifications;

use PDO; use VO\Database\PdoConnection; use VO\Settings\SettingsRepository;

/**
 * Builds the channel transports from business settings (design decision 10):
 * a channel whose flag is on and whose configuration is valid gets a real
 * client (SMTP with STARTTLS on, WhatsApp bridge with bearer token); disabled
 * or unconfigured channels get null, which the sweep records as the typed
 * "canal no configurado" failure instead of throwing (spec N4). Settings reads
 * are cached per SettingsRepository instance, so one construction per request
 * stays cheap.
 */
final class NotificationTransportFactory
{
    /** @return array{0:?NotificationTransport,1:?NotificationTransport} [smtp, whatsapp] */
    public static function transports(SettingsRepository $settings, int $businessId): array
    {
        if ($settings->get($businessId, 'notifications_enabled') !== '1') return [null, null];
        $smtp = null;
        if ($settings->get($businessId, 'notifications_email_enabled') === '1') {
            $host = trim((string)$settings->get($businessId, 'smtp_host'));
            if ($host !== '') {
                $port = (int)$settings->get($businessId, 'smtp_port');
                $smtp = new SmtpClient($host, $port >= 1 && $port <= 65535 ? $port : 587,
                    (string)$settings->get($businessId, 'smtp_username'), (string)$settings->get($businessId, 'smtp_password'),
                    (string)$settings->get($businessId, 'smtp_from_email'), (string)$settings->get($businessId, 'smtp_from_name'));
            }
        }
        $whatsapp = null;
        if ($settings->get($businessId, 'notifications_whatsapp_enabled') === '1') {
            $url = trim((string)$settings->get($businessId, 'whatsapp_bridge_url'));
            if ($url !== '') $whatsapp = new WhatsAppClient($url, (string)$settings->get($businessId, 'whatsapp_bridge_token'));
        }
        return [$smtp, $whatsapp];
    }

    /** Request-scoped NotificationService for a read point / sweep trigger. */
    public static function service(PDO $pdo, int $businessId): NotificationService
    {
        $db = new PdoConnection('', factory: static fn() => $pdo);
        [$smtp, $whatsapp] = self::transports(new SettingsRepository($pdo), $businessId);
        return new NotificationService($db, new NotificationRepository($db), new SettingsRepository($pdo), $smtp, $whatsapp);
    }
}
