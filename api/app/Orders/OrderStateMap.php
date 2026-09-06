<?php declare(strict_types=1);
namespace VO\Orders;

use VO\Domain\StateMachine;

final class OrderStateMap
{
    public static function create(): StateMachine
    {
        $states = ['pending','change_proposed','accepted','in_progress','ready','completed','rejected','cancelled','expired'];
        $transitions = [
            'pending' => ['change_proposed','accepted','rejected','cancelled','expired'],
            'change_proposed' => ['accepted','rejected','cancelled','expired'],
            'accepted' => ['in_progress','cancelled'],
            'in_progress' => ['ready','cancelled'],
            'ready' => ['completed','cancelled'],
            'completed' => [],
            'rejected' => [],
            'cancelled' => [],
            'expired' => [],
        ];
        return new StateMachine($states, $transitions);
    }
}
