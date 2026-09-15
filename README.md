# Utopia Payments for PHP

The official PHP library for the
[Utopia Payments API](https://utopia-payments.com/docs/): hosted checkout,
subscriptions with automatic renewals, and signed webhooks.

- PHP 8.1+, cURL, no other dependencies
- Every create sends an `Idempotency-Key`, so retries never double-charge
- Network errors, `429` and `5xx` are retried with backoff
- `all()` walks every page of a list
- `Webhook::verify()` checks [Standard Webhooks](https://standardwebhooks.com) signatures

## Install

```bash
composer require utopia-payments/utopia-php
```

## Quick start

Create a secret key in **Dashboard → Developers** and keep it on your server.

```php
use Utopia\Utopia;

$utopia = new Utopia(getenv('UTOPIA_API_KEY'));

$session = $utopia->checkoutSessions->create([
    'product_cart' => [['product_id' => 'pdt_…', 'quantity' => 1]],
    'customer' => ['email' => 'customer@example.com'],
    'return_url' => 'https://your-store.com/thank-you',
    'metadata' => ['order_id' => '1001'],
]);

header('Location: ' . $session['checkout_url']);
```

Amounts are integers in minor units: `34900` is AED 349.00. Responses are
associative arrays shaped like the API's JSON.

Carts that don't map to saved products can price items inline:

```php
$utopia->checkoutSessions->create([
    'product_cart' => [['name' => 'Order #1001', 'unit_amount' => 12550, 'currency' => 'AED', 'quantity' => 2]],
    'return_url' => 'https://your-store.com/orders/1001',
]);
```

## Subscriptions

```php
$plan = $utopia->products->create([
    'name' => 'Pro',
    'price' => 9900,
    'currency' => 'AED',
    'billing' => 'recurring',
    'billing_interval' => 'month',
]);

$session = $utopia->checkoutSessions->create([
    'product_cart' => [['product_id' => $plan['product_id']]],
    'customer' => ['email' => 'member@example.com'],
]);

// Later: stop renewing when the paid period ends.
$utopia->subscriptions->cancel('sub_…', atPeriodEnd: true);
```

## Webhooks

Verify every delivery against the raw body before trusting it:

```php
use Utopia\Webhook;
use Utopia\Exception\WebhookVerificationException;

$payload = file_get_contents('php://input');

try {
    $event = Webhook::verify($payload, getallheaders(), getenv('UTOPIA_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'payment.succeeded') {
    fulfil($event['data']['metadata']['order_id']);
}
http_response_code(204);
```

Laravel:

```php
Route::post('/webhooks/utopia', function (Request $request) {
    $event = \Utopia\Webhook::verify(
        $request->getContent(),
        $request->headers->all(),
        config('services.utopia.webhook_secret'),
    );
    // …
    return response()->noContent();
});
```

Deliveries retry for about a day until you answer with a 2xx. Use
`$event['id']` to ignore duplicates.

## Lists

```php
$page = $utopia->payments->list(['limit' => 50, 'status' => 'succeeded']);

foreach ($utopia->payments->all(['limit' => 100]) as $payment) {
    echo $payment['payment_id'], PHP_EOL;
}
```

## Errors

```php
use Utopia\Exception\NotFoundException;
use Utopia\Exception\UtopiaException;

try {
    $utopia->payments->retrieve('pay_…');
} catch (NotFoundException $e) {
    // …
} catch (UtopiaException $e) {
    echo $e->getHttpStatus(), ' ', $e->getErrorCode(), ': ', $e->getMessage();
}
```

Exception classes: `InvalidRequestException`, `AuthenticationException`,
`PermissionDeniedException`, `NotFoundException`, `ConflictException`,
`RateLimitException`, `ApiException`, `ApiConnectionException`,
`WebhookVerificationException`.

## Options

```php
new Utopia('sk_live_…', [
    'timeout' => 30,     // seconds
    'max_retries' => 2,
    'base_url' => 'https://utopia-payments.com/api/v1',
]);
```
