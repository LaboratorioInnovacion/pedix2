<?php declare(strict_types=1);
namespace VO\Delivery;

use VO\Domain\StateMachine;

/**
 * Delivery lifecycle per spec D5:
 * pending → assigned | cancelled
 * assigned → picked_up | assigned (reassign) | failed | cancelled
 * picked_up → delivered | failed | cancelled
 * delivered | failed | cancelled → terminal.
 */
final class DeliveryStateMap
{
    public const STATES = ['pending', 'assigned', 'picked_up', 'delivered', 'failed', 'cancelled'];
    /** States from which the row is still active (cancellable by order-cancel cascade). */
    public const ACTIVE_STATES = ['pending', 'assigned', 'picked_up'];

    public static function create(): StateMachine
    {
        return new StateMachine(self::STATES, [
            'pending' => ['assigned', 'cancelled'],
            'assigned' => ['picked_up', 'assigned', 'failed', 'cancelled'],
            'picked_up' => ['delivered', 'failed', 'cancelled'],
            'delivered' => [],
            'failed' => [],
            'cancelled' => [],
        ]);
    }
}
