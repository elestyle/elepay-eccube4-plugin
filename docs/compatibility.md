# EC-CUBE compatibility

One codebase supports EC-CUBE 4.2, 4.3 and 4.4 (plugin code `elepay42`).

| | EC-CUBE 4.2 | EC-CUBE 4.3 | EC-CUBE 4.4 |
|---|---|---|---|
| PHP | 7.4 - 8.1 | 8.1 - 8.3 | 8.2 - 8.5 |
| Symfony | 5.4 | 6.4 | 7.4 |
| Doctrine ORM | 2.x | 2.x | 3 |

Every construct must be valid PHP 7.4 and equivalent (or ignored) on the older versions.

## Rules

1. **Routes and Doctrine mappings are declared twice**: as docblock annotations (read by 4.2/4.3) and as PHP attributes (read by 4.4). This covers `@Route`, `@ORM\*` and `@EntityExtension` (attribute `\Eccube\Attribute\EntityExtension`). On PHP 8, 4.2/4.3 read both and the later one wins, so the two must be identical.
2. **Write every attribute on a single line.** PHP 7.4 skips a single-line `#[...]` as a comment; a multi-line one breaks parsing.
3. **PHP 7.4 syntax only**: no union types, no named arguments. Validator constraints take positional arguments (also avoids the array-option deprecation of Symfony 7.3+).
4. **Return types** required by 4.4 are added on `PaymentMethodInterface` implementations and on `AbstractPluginManager` lifecycle methods (`: void`, `: bool`, `: PaymentResult`, `: PaymentMethodInterface`); they are valid on 4.2/4.3.
5. **Use APIs that exist on all supported versions**: `Symfony\Component\Routing\Annotation\Route` (not `Attribute\Route`, which 4.2 lacks), `Psr\Container\ContainerInterface`, session through `RequestStack`, `EntityManager::flush()` without an entity argument, `dispatch($event, $eventName)`.
6. **Admin templates work with Bootstrap 4 and 5**: give badges both class sets (`badge-primary bg-primary`) and do not rely on `input-group-append`.
7. **`PaymentMethodInterface::verify()` returns `false`** and `apply()` sets a placeholder response; both exist because of 4.4 dereferencing the dispatcher response (see the comments in `Service/Method/Elepay.php`).

## Known limitation

`Symfony\Component\Routing\Annotation\Route` is removed in Symfony 8. It cannot be replaced while EC-CUBE 4.2 is supported.
