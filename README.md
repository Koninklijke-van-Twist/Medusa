# Ploutos

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git), mét de Business Central-credentials ernaast:

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// BC-credentials blijven verplicht naast $mimirApi (fallback als Mímir uitvalt):
$auth_list = [ /* environment => ['mode' => 'basic'|'ntlm', 'user' => '…', 'pass' => '…'] */ ];
$environment = 'Production';
$auth = $auth_list[$environment];
$baseUrl = 'https://mijn-bc-host:7148/';
```

Met `$mimirApi` gezet proberen OData-fetches, `odata_debug_fetch_raw` en company-discovery (`auth_discover_companies_across_active_environments` / `odata_mimir_list_companies`) eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Medusa dezelfde gegevens op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Ontbreken die BC-credentials, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft alleen het bestaande BC-pad actief.

`goedkeuren.php` laadt `auth.php` altijd, zowel als webverzoek als via de CLI (`php web/goedkeuren.php`). Er is geen aparte nightly-entrypoint; een CLI-run gebruikt dezelfde fallback, met een Mímir-timeout van 600s. Webverzoeken (php-fpm/apache) gebruiken ongeveer 90s, met een connect-timeout van 10s.
