<?php declare(strict_types=1);
namespace VO\Payments;

use DomainException;
use Throwable;
use VO\Http\JsonResponse;
use VO\Http\Request;

final class MpWebhookController
{
    public function __construct(private PaymentService $payments) {}

    public function handle(Request $request): JsonResponse
    {
        $raw = (string)file_get_contents('php://input');
        try {
            $this->payments->handleMpWebhook(
                $raw,
                is_string($request->header('x-signature')) ? $request->header('x-signature') : null,
                is_string($request->header('x-request-id')) ? $request->header('x-request-id') : null
            );
            return new JsonResponse(['received'=>true]);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'INVALID_MP_SIGNATURE') return new JsonResponse(['received'=>false], 401);
            error_log('Mercado Pago webhook ignored: ' . $e->getMessage());
            return new JsonResponse(['received'=>true]);
        } catch (Throwable $e) {
            error_log('Mercado Pago webhook failed: ' . $e->getMessage());
            return new JsonResponse(['received'=>true]);
        }
    }
}
