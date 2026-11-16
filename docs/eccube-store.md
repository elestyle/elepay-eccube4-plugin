# EC-CUBE store registration (オーナーズストア, Owners Store)

Terms in 「」 are labels as the store shows them in Japanese, kept verbatim so they can be found on the store's forms.

The store review asks for the 「戻り先URL」 (return URL) of the verification environment. The value is the shop root URL of the verification environment (ending in `/html/`), not a route of this plugin; `/elepay_checkout_validate` lives beneath it and is not listed separately.

## Format (plugin)

```
https://[verification-env].ec-cube.net/①/eccube-②-mysql/html/
https://[verification-env].ec-cube.net/①/eccube-②-pgsql/html/
```

- [verification-env]: `check74` for EC-CUBE 4.0/4.1, `check81` for 4.2/4.3. Confirm the environment for 4.4 with the store's current instructions.
- ①: the numeric product ID that the store assigned to this plugin (known only to the applicant).
- ②: the last EC-CUBE version the plugin supports; for 4.3.0 / 4.3.1 specify `4.3.1`.

Type is 「プラグイン」 (Plugin; this is an `eccube-plugin`, not a legacy `mdl_*` module).

## Value for this plugin

When the supported range was 4.2 / 4.3, the URLs were:

```
https://check81.ec-cube.net/[product-id]/eccube-4.3.1-mysql/html/
https://check81.ec-cube.net/[product-id]/eccube-4.3.1-pgsql/html/
```

Since the plugin now declares 4.4 support (`composer.json`, `README.md`), ② follows the last supported 4.4 version and the verification environment must be the one the store lists for 4.4. Check both against the store's instructions when registering or updating the product, and replace [product-id] with the plugin's product ID.
