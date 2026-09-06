<?php declare(strict_types=1);
namespace VO\Delivery;

use VO\Cart\CartValidationException;

/**
 * Typed error raised when a delivery checkout has no matching active zone.
 * Extends CartValidationException so page/JSON cart flows handle it as a 422.
 */
final class DeliveryUnavailableException extends CartValidationException
{
    public const CODE = 'delivery_unavailable';

    public function __construct(string $message = 'No tenemos cobertura de envío para esa dirección.')
    {
        parent::__construct($message, 422);
    }
}
