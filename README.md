# rossaddison/bitpay-client

A small, hand-written PHP client for the [BitPay API](https://developer.bitpay.com/docs)
POS facade, built for [rossaddison/invoice](https://github.com/rossaddison/invoice).

## Why not BitPay's own official SDK?

BitPay publishes an official, actively-maintained SDK, [`bitpay/sdk`](https://github.com/bitpay/php-bitpay-client-v2).
It's a good package — this isn't a code-quality replacement the way
[rossaddison/storecove-client](https://github.com/rossaddison/storecove-client)
replaced two unusable OpenAPI-generated clients. It's a dependency-graph
conflict: every published version of `bitpay/sdk`, back through its full
version history, requires `symfony/console ^7.3.1` at most —
`rossaddison/invoice` pins `symfony/console`/`symfony/process` to `>=8.1.6`
with no ceiling, and `composer require bitpay/sdk --dry-run
--with-all-dependencies` confirms there's no version of either package that
satisfies both constraints at once.

BitPay's **POS facade** — the token-authenticated part of the API that
doesn't need the SDK's ECDSA client-identity key-pairing — is a plain JSON
API simple enough that hand-writing the two endpoints
`rossaddison/invoice` actually needs was less work than fighting the
Composer conflict. Confirmed directly against BitPay's own OpenAPI
reference: `X-Identity`/`X-Signature` are "optional for this endpoint when
using the public facade, and required when using a `merchant` facade
token" on both `POST /invoices` and `GET /invoices/{id}` — the `token`
parameter alone is sufficient for the POS facade on both.

## What's here

- `BitPayClient` — `createInvoice()`, `getInvoice()`, and
  `verifyWebhookSignature()`.
- `Model\CreateInvoiceRequest`, `Model\Invoice` — the request/response
  shapes those need.
- `Exception\BitPayApiException` — thrown on a non-2xx response, carrying
  the raw response body.

Deliberately **not** here: refunds, payouts, ledgers, recipients, or
anything else needing BitPay's merchant facade — all of those need the
ECDSA key-pairing model this package doesn't implement. BitPay refunds
must be issued manually via the merchant dashboard for now; the same
limitation `rossaddison/invoice` already documents for its TrueLayer
integration, for an unrelated reason (TrueLayer's own external-account
payments aren't refundable via API at all).

## Usage

```php
use RossAddison\BitPayClient\BitPayClient;
use RossAddison\BitPayClient\Model\CreateInvoiceRequest;

$client = new BitPayClient(
    token: $posToken,
    baseUri: $sandbox ? BitPayClient::TEST_BASE_URI : BitPayClient::PRODUCTION_BASE_URI,
);

$invoice = $client->createInvoice(new CreateInvoiceRequest(
    price: 59.40,
    currency: 'GBP',
    orderId: $invoiceUrlKey,
    redirectUrl: $returnUrl,
    notificationUrl: $webhookUrl,
));

// $invoice->url — redirect the customer here
// $invoice->id  — store this against the invoice for later status lookup
```

Verifying a webhook and re-confirming before marking anything paid —
**always do both**, the same belt-and-braces pattern
`rossaddison/invoice` uses for every gateway it integrates:

```php
if ($client->verifyWebhookSignature($rawRequestBody, $request->getHeaderLine('x-signature'))) {
    $invoice = $client->getInvoice($invoiceId);
    if ($invoice->isSettled()) {
        // mark paid
    }
}
```

## A note on the webhook signature

`x-signature` is `base64(HMAC-SHA256(rawBody, token))` over the **raw
request body bytes exactly as received** — no JSON parsing, no
re-serialization. Confirmed live 2026-09-05 against real BitPay-signed
webhook deliveries (`rossaddison/invoice`, a real sandbox account):
logging the HMAC computed over the literal raw body alongside the
received `x-signature` header showed an exact match.

An earlier revision of this method parsed the body then re-encoded it
first, on the theory that BitPay's own reference verifier
([`bitpay/hmac-tester`](https://github.com/bitpay/hmac-tester)) computes
`JSON.stringify(req.body)` rather than hashing the wire bytes directly.
That turned out to be wrong for real payloads: BitPay's actual webhook
bodies carry large numeric values in scientific notation (e.g.
`"MATIC":1.699895497e+21`, present in every real invoice's
`paymentSubtotals`/`paymentTotals`), and PHP's `json_decode()` /
`json_encode()` round-trip doesn't reliably reproduce the exact same
numeric string for values like that — silently changing the bytes being
signed, and therefore the HMAC, for every real invoice notification.
Hashing the literal raw bytes sidesteps that entirely.

Still only a first gate, not the sole check — always re-confirm via
`getInvoice()` before marking anything paid, exactly as BitPay's own
docs recommend ("the IPN should be used as a trigger to verify the
status of a specific invoice").
