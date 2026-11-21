Honour signed DevTools overrides on server reads. Assumes `Shipeasy\configure()`
ran at startup — see Installation. Under PHP-FPM this is automatic
(`$_COOKIE['se_ov']`); elsewhere scope the request's `Cookie` header:

```php
use Shipeasy\Client;

$response = Shipeasy\withRequestOverrides(
    $request->headers->get('Cookie'),   // raw Cookie header (or the bare se_ov value); null = no overrides
    function () use ($currentUser) {    // reads inside see the verified overrides, then the scope clears
        $client = new Client($currentUser);   // construct once per callsite
        return $client->getFlag('{{FLAG_KEY}}');
    },
    $request->headers->get('X-Shipeasy-Overrides'),   // optional: header fallback for sibling-subdomain / token-auth APIs
);
```
