<?php declare(strict_types=1);
namespace VO\Payments;

use VO\Domain\StateMachine;

final class PaymentStateMap
{
    public static function create(): StateMachine
    {
        $states = ['pending','approved','rejected','cancelled','pending_verification','verified','refund_pending','refund_completed'];
        $transitions = [
            'pending' => ['approved','rejected','cancelled'],
            'approved' => [],
            'rejected' => [],
            'cancelled' => [],
            'pending_verification' => ['verified','rejected','cancelled'],
            'verified' => [],
            'refund_pending' => ['refund_completed'],
            'refund_completed' => [],
        ];
        return new StateMachine($states, $transitions);
    }
}
