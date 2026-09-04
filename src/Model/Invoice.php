<?php

declare(strict_types=1);

namespace RossAddison\BitPayClient\Model;

/**
 * The `data` object BitPay returns from both POST /invoices and
 * GET /invoices/{id}.
 *
 * Status values, confirmed against developer.bitpay.com/docs/invoice-states:
 * `new` (accepting payment, 15-minute window) -> `paid` (received, unconfirmed)
 * -> `confirmed` (validated on-chain) -> `complete` (merchant account
 * credited — BitPay's own docs: "Orders are only credited to the BitPay
 * Account for settlement after the invoice reaches the status complete").
 * `expired` and `complete` are the two terminal states; `invalid` (payment
 * seen but still unconfirmed after an hour) can still resolve to
 * `confirmed`/`complete` later, so it is deliberately not terminal.
 * `complete` — not `confirmed` — is what `isSettled()` checks: this is an
 * invoicing/accounting package, not an instant-delivery storefront, so it
 * waits for BitPay's own "safe to fulfil" bar rather than the earlier
 * on-chain-only checkpoint.
 */
final readonly class Invoice
{
    public function __construct(
        public string $id,
        public string $url,
        public string $status,
        public float $price,
        public string $currency,
        public ?string $orderId = null,
    ) {
    }

    public function isSettled(): bool
    {
        return $this->status === 'complete';
    }

    /**
     * @param array{id: string, url: string, status: string, price: float|int, currency: string, orderId?: string|null} $json
     */
    public static function fromArray(array $json): self
    {
        return new self(
            id: $json['id'],
            url: $json['url'],
            status: $json['status'],
            price: (float) $json['price'],
            currency: $json['currency'],
            orderId: $json['orderId'] ?? null,
        );
    }
}
