<?php

declare(strict_types=1);

namespace RossAddison\BitPayClient\Test;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RossAddison\BitPayClient\BitPayClient;
use RossAddison\BitPayClient\Exception\BitPayApiException;
use RossAddison\BitPayClient\Model\CreateInvoiceRequest;

final class BitPayClientTest extends TestCase
{
    private function clientWithQueuedResponses(MockHandler $mock): BitPayClient
    {
        $handlerStack = HandlerStack::create($mock);
        $http = new HttpClient(['handler' => $handlerStack]);

        return new BitPayClient(token: 'test-token', http: $http);
    }

    public function testCreateInvoiceReturnsInvoiceOnSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    'id' => 'inv-123',
                    'url' => 'https://bitpay.com/invoice?id=inv-123',
                    'status' => 'new',
                    'price' => 59.40,
                    'currency' => 'GBP',
                    'orderId' => 'INV-0042',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = $this->clientWithQueuedResponses($mock);

        $invoice = $client->createInvoice(new CreateInvoiceRequest(
            price: 59.40,
            currency: 'GBP',
            orderId: 'INV-0042',
        ));

        $this->assertSame('inv-123', $invoice->id);
        $this->assertSame('https://bitpay.com/invoice?id=inv-123', $invoice->url);
        $this->assertSame('new', $invoice->status);
        $this->assertSame(59.40, $invoice->price);
        $this->assertSame('GBP', $invoice->currency);
        $this->assertSame('INV-0042', $invoice->orderId);
        $this->assertFalse($invoice->isSettled());
    }

    /**
     * Regression test: an earlier revision of getInvoice() sent this same
     * request to 'api/invoices/...' instead of 'invoices/...' — a
     * copy-paste-era path that was never actually re-verified against
     * BitPay's own OpenAPI reference the way createInvoice()'s path was.
     * Asserting the exact method+path here (not just that a response was
     * successfully parsed, which a wrong path's mocked response would
     * still satisfy) is what would have caught it.
     */
    public function testCreateInvoiceSendsAPostToTheInvoicesPath(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    'id' => 'inv-123',
                    'url' => 'https://bitpay.com/invoice?id=inv-123',
                    'status' => 'new',
                    'price' => 59.40,
                    'currency' => 'GBP',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = $this->clientWithQueuedResponses($mock);

        $client->createInvoice(new CreateInvoiceRequest(price: 59.40, currency: 'GBP', orderId: 'INV-0042'));

        $sentRequest = $mock->getLastRequest();
        $this->assertNotNull($sentRequest);
        $this->assertSame('POST', $sentRequest->getMethod());
        $this->assertSame('invoices', $sentRequest->getUri()->getPath());
    }

    public function testCreateInvoiceThrowsOnNon2xxResponse(): void
    {
        $mock = new MockHandler([
            new Response(422, [], json_encode(['error' => 'invalid currency'], JSON_THROW_ON_ERROR)),
        ]);
        $client = $this->clientWithQueuedResponses($mock);

        $this->expectException(BitPayApiException::class);

        $client->createInvoice(new CreateInvoiceRequest(
            price: 59.40,
            currency: 'XXX',
            orderId: 'INV-0042',
        ));
    }

    public function testCreateInvoiceThrowsWhenResponseHasNoDataEnvelope(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['unexpected' => 'shape'], JSON_THROW_ON_ERROR)),
        ]);
        $client = $this->clientWithQueuedResponses($mock);

        $this->expectException(BitPayApiException::class);

        $client->createInvoice(new CreateInvoiceRequest(
            price: 59.40,
            currency: 'GBP',
            orderId: 'INV-0042',
        ));
    }

    /**
     * See testCreateInvoiceSendsAPostToTheInvoicesPath()'s own docblock —
     * the same class of regression, for getInvoice()'s own path, which is
     * where the actual bug was found.
     */
    public function testGetInvoiceSendsAGetToTheInvoicesIdPath(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    'id' => 'inv-123',
                    'url' => 'https://bitpay.com/invoice?id=inv-123',
                    'status' => 'complete',
                    'price' => 59.40,
                    'currency' => 'GBP',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = $this->clientWithQueuedResponses($mock);

        $client->getInvoice('inv-123');

        $sentRequest = $mock->getLastRequest();
        $this->assertNotNull($sentRequest);
        $this->assertSame('GET', $sentRequest->getMethod());
        $this->assertSame('invoices/inv-123', $sentRequest->getUri()->getPath());
        $this->assertSame('token=test-token', $sentRequest->getUri()->getQuery());
    }

    public function testGetInvoiceReturnsInvoiceMarkedSettledWhenComplete(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    'id' => 'inv-123',
                    'url' => 'https://bitpay.com/invoice?id=inv-123',
                    'status' => 'complete',
                    'price' => 59.40,
                    'currency' => 'GBP',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = $this->clientWithQueuedResponses($mock);

        $invoice = $client->getInvoice('inv-123');

        $this->assertSame('complete', $invoice->status);
        $this->assertTrue($invoice->isSettled());
    }

    #[DataProvider('nonSettledStatusProvider')]
    public function testIsSettledIsFalseForEveryNonCompleteStatus(string $status): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    'id' => 'inv-123',
                    'url' => 'https://bitpay.com/invoice?id=inv-123',
                    'status' => $status,
                    'price' => 59.40,
                    'currency' => 'GBP',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = $this->clientWithQueuedResponses($mock);

        $invoice = $client->getInvoice('inv-123');

        $this->assertFalse($invoice->isSettled());
    }

    /** @return list<list<string>> */
    public static function nonSettledStatusProvider(): array
    {
        return [
            ['new'],
            ['paid'],
            ['confirmed'],
            ['expired'],
            ['invalid'],
        ];
    }

    public function testVerifyWebhookSignatureAcceptsAMatchingSignature(): void
    {
        $client = new BitPayClient(token: 'shared-secret');

        $body = json_encode(['id' => 'inv-123', 'status' => 'complete'], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', (string) $body, 'shared-secret', true));

        $this->assertTrue($client->verifyWebhookSignature((string) $body, $signature));
    }

    public function testVerifyWebhookSignatureRejectsAWrongSignature(): void
    {
        $client = new BitPayClient(token: 'shared-secret');

        $body = json_encode(['id' => 'inv-123', 'status' => 'complete'], JSON_THROW_ON_ERROR);

        $this->assertFalse($client->verifyWebhookSignature((string) $body, 'not-the-right-signature'));
    }

    /**
     * The signature is computed over the raw bytes with no JSON parsing
     * step at all — confirmed directly against real BitPay webhook
     * deliveries (see this method's own docblock). A malformed body isn't
     * specially rejected; it just needs its own correctly-computed
     * signature like any other body, proving no JSON validation happens.
     */
    public function testVerifyWebhookSignatureAcceptsAMatchingSignatureEvenOverMalformedJson(): void
    {
        $client = new BitPayClient(token: 'shared-secret');

        $body = '{not valid json';
        $signature = base64_encode(hash_hmac('sha256', $body, 'shared-secret', true));

        $this->assertTrue($client->verifyWebhookSignature($body, $signature));
    }

    /**
     * Confirms the signature is sensitive to the exact raw bytes, not a
     * parsed-then-reencoded canonical form — the opposite of an earlier
     * revision of this method, which parsed and re-encoded the body first
     * and was proven wrong against real BitPay webhook deliveries (see
     * BitPayClient::verifyWebhookSignature()'s own docblock: PHP's
     * json_decode()/json_encode() round-trip doesn't reliably reproduce
     * the same numeric string for values like the scientific notation
     * BitPay's real invoice payloads carry, e.g. "MATIC":1.699895497e+21).
     */
    public function testVerifyWebhookSignatureRejectsADifferentlyFormattedButLogicallyIdenticalBody(): void
    {
        $client = new BitPayClient(token: 'shared-secret');

        $compact = json_encode(['id' => 'inv-123', 'status' => 'complete'], JSON_THROW_ON_ERROR);
        $pretty = json_encode(
            ['id' => 'inv-123', 'status' => 'complete'],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        $signature = base64_encode(hash_hmac('sha256', (string) $compact, 'shared-secret', true));

        $this->assertFalse($client->verifyWebhookSignature((string) $pretty, $signature));
    }
}
