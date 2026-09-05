<?php

declare(strict_types=1);

namespace RossAddison\BitPayClient;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RossAddison\BitPayClient\Exception\BitPayApiException;
use RossAddison\BitPayClient\Model\CreateInvoiceRequest;
use RossAddison\BitPayClient\Model\Invoice;

/**
 * Minimal BitPay API client — deliberately only wraps the POS facade
 * (POST /invoices, GET /invoices/{id}, and x-signature webhook
 * verification), rather than BitPay's full merchant-facade surface
 * (refunds, payouts, ledgers, ...), which needs the ECDSA client-identity
 * key-pairing model this package doesn't implement at all.
 *
 * Built hand-written rather than on BitPay's own official `bitpay/sdk`
 * package for a structural reason, not a code-quality one (contrast
 * rossaddison/storecove-client's own README, which replaced two
 * OpenAPI-generated clients that were unusable at Psalm level 1):
 * `bitpay/sdk` requires `symfony/console ^7.3.1` at most across every
 * published version back through its history, but rossaddison/invoice
 * pins `symfony/console`/`symfony/process` to `>=8.1.6` with no ceiling —
 * a genuine, unresolvable Composer conflict, confirmed via
 * `composer require bitpay/sdk --dry-run --with-all-dependencies`.
 * BitPay's POS facade is a plain token-authenticated JSON API (confirmed
 * directly against developer.bitpay.com's own OpenAPI reference for both
 * "Create an Invoice" and "Retrieve an Invoice" — the pos facade needs
 * only the `token` parameter, X-Identity/X-Signature are explicitly
 * "optional for this endpoint when using the public facade, and required
 * when using a merchant facade token"), simple enough that hand-writing
 * it avoids the conflict entirely rather than trying to work around it.
 *
 * @see https://developer.bitpay.com/reference/create-an-invoice
 * @see https://developer.bitpay.com/reference/retrieve-an-invoice
 * @see https://developer.bitpay.com/reference/hmac-verification
 */
final readonly class BitPayClient
{
    public const string PRODUCTION_BASE_URI = 'https://bitpay.com/';
    public const string TEST_BASE_URI = 'https://test.bitpay.com/';

    private const string API_VERSION = '2.0.0';

    private ClientInterface $http;

    /**
     * @param string $token The POS facade token from the BitPay merchant
     *     dashboard — the same credential used for every call this client
     *     makes, and the HMAC secret for verifyWebhookSignature().
     * @param string $baseUri Pass self::TEST_BASE_URI for sandbox. Callers
     *     decide which — this package has no concept of its own "sandbox
     *     mode" flag, matching StorecoveClient's own constructor shape.
     */
    public function __construct(
        private string $token,
        ?ClientInterface $http = null,
        string $baseUri = self::PRODUCTION_BASE_URI,
    ) {
        $this->http = $http ?? new HttpClient(['base_uri' => $baseUri]);
    }

    /**
     * @throws BitPayApiException on a non-2xx response, or a 2xx response
     *     that doesn't carry the `data` envelope every successful BitPay
     *     response uses
     * @throws GuzzleException on a transport-level failure (DNS, timeout, ...)
     */
    public function createInvoice(CreateInvoiceRequest $request): Invoice
    {
        $body = $request->toArray();
        $body['token'] = $this->token;

        $response = $this->http->request('POST', 'invoices', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Accept-Version' => self::API_VERSION,
            ],
            'json' => $body,
            'http_errors' => false,
        ]);

        return $this->decodeInvoice($response);
    }

    /**
     * @throws BitPayApiException on a non-2xx response
     * @throws GuzzleException on a transport-level failure
     */
    public function getInvoice(string $invoiceId): Invoice
    {
        $response = $this->http->request('GET', 'invoices/' . rawurlencode($invoiceId), [
            'headers' => [
                'X-Accept-Version' => self::API_VERSION,
            ],
            'query' => ['token' => $this->token],
            'http_errors' => false,
        ]);

        return $this->decodeInvoice($response);
    }

    /**
     * Verifies an incoming webhook's `x-signature` header: a base64
     * HMAC-SHA256 over the raw JSON body bytes exactly as received, keyed
     * by the same token used to create the resource.
     *
     * Confirmed live 2026-09-05 against real BitPay-signed webhook
     * deliveries (rossaddison/invoice, a real sandbox account): logging
     * the computed HMAC over the literal raw body alongside the received
     * `x-signature` header showed an exact byte-for-byte match. An
     * earlier revision of this method parsed then re-encoded the body
     * first (mirroring what BitPay's own reference verifier,
     * github.com/bitpay/hmac-tester, appears to do in
     * `JSON.stringify(req.body)`) — that was wrong for real payloads:
     * BitPay's actual webhook bodies carry large numeric values in
     * scientific notation (e.g. `"MATIC":1.699895497e+21`, from
     * `paymentSubtotals`/`paymentTotals`), and PHP's `json_decode()` /
     * `json_encode()` round-trip doesn't reliably reproduce the exact
     * same numeric string for values like that — silently changing the
     * bytes being signed, and therefore the HMAC, for every real invoice
     * body (which always carries these fields). Hashing the literal raw
     * bytes, with no parsing step at all, sidesteps that entirely and is
     * what real BitPay webhooks actually match against.
     *
     * Still only the first gate, never the sole check — every caller in
     * rossaddison/invoice re-confirms via an authenticated getInvoice()
     * before marking anything paid regardless of whether this returns
     * true, matching BitPay's own guidance ("the IPN should be used as
     * a trigger to verify the status of a specific invoice") and the
     * same belt-and-braces pattern this app already uses for every
     * other signed-webhook gateway.
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $this->token, true));

        return hash_equals($expected, $signatureHeader);
    }

    private function decodeInvoice(ResponseInterface $response): Invoice
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new BitPayApiException($status, $body);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new BitPayApiException($status, $body);
        }

        /** @var array{id: string, url: string, status: string, price: float|int, currency: string, orderId?: string|null} $data */
        $data = $decoded['data'];

        return Invoice::fromArray($data);
    }
}
