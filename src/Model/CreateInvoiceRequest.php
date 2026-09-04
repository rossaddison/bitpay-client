<?php

declare(strict_types=1);

namespace RossAddison\BitPayClient\Model;

/**
 * Request body for POST /invoices via the POS facade. `token` is
 * deliberately not a field here — BitPayClient adds it itself from the
 * credential it was constructed with, the same way StorecoveClient never
 * takes an apiKey per-request either.
 */
final readonly class CreateInvoiceRequest
{
    public function __construct(
        public float $price,
        /** ISO 4217 3-character currency code, e.g. 'GBP', 'USD'. */
        public string $currency,
        /**
         * This app's own invoice url_key — carried through unchanged in
         * BitPay's response and, since it's just an opaque merchant
         * reference to BitPay, also echoed back on webhook notifications,
         * so the invoice can be resolved without a second lookup.
         */
        public string $orderId,
        /** Where the customer lands after paying (or cancelling). */
        public ?string $redirectUrl = null,
        /** BitPay's webhook target for this invoice specifically. */
        public ?string $notificationUrl = null,
        public ?string $buyerEmail = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'price' => $this->price,
            'currency' => $this->currency,
            'orderId' => $this->orderId,
            'redirectURL' => $this->redirectUrl,
            'notificationURL' => $this->notificationUrl,
            'buyerEmail' => $this->buyerEmail,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
