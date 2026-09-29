# README

## How to Installation
Please see the instructions for user installation [here](https://developer.elepay.io/docs/ec-cube-plugin).

## Development Infomations
### Supported versions

EC-CUBE 4.2 / 4.3 / 4.4 (plugin code `elepay42`), all from one codebase. The lowest runtime is PHP 7.4 (EC-CUBE 4.2), so code must stay valid on it.

### Install dependencies

`Resource/vendor/` (gitignored) holds `elepay-php-sdk` and its dependencies. Generate it with:

```shell
./build-vendor.sh
```

It resolves the dependencies against PHP 7.4 regardless of the local PHP, appends the bundled autoloader after the host's, and patches the SDK's implicit nullable parameters. It falls back to Docker when composer is not installed.

### Development rules

- Routes and Doctrine mappings (including `@EntityExtension`) are declared twice: as docblock annotations (EC-CUBE 4.2/4.3) and as PHP attributes (EC-CUBE 4.4). Keep the two identical, and write every attribute on a single line, since PHP 7.4 treats a single-line `#[...]` as a comment.
- Use only PHP 7.4 syntax: no union types, no named arguments (e.g. validator constraints take positional arguments).

### Documents

- [docs/architecture.md](docs/architecture.md): payment flow, order status handling, concurrency, ownership rule, plugin lifecycle
- [docs/compatibility.md](docs/compatibility.md): EC-CUBE 4.2 / 4.3 / 4.4 compatibility rules
- [docs/eccube-store.md](docs/eccube-store.md): 「戻り先URL」 for the EC-CUBE store (オーナーズストア)

### Directory description

- `Controller\` Route Controller
- `Entity\` Database table definition classes, where files ending in 'traits' are used to extend database tables
- `Form\Type\Admin\ConfigType.php` Plugin setup page for form building
- `Repository\` Operation extension classes for database tables
- `Resource\` Static configuration files, resource files, and template files
- `Service\` Tool class
- `Service\Method\` Code that executes globally by default
- `composer.json` Package management
- `Event.php` Use the template render event to do the corresponding processing
- `PluginManager.php` Main
