# SearchKit

Search management and intelligence for Craft CMS.

## Status

**Early development.** This repository currently contains the plugin foundation only — the main
plugin class, the install/uninstall migration lifecycle, and Composer autoloading. None of the
features below are implemented yet.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Planned features

None of these exist yet. They are the intended direction of the plugin, not a description of what
it currently does.

- Search management
- Multiple search providers
- Index management
- Search rules and merchandising
- Search insights and analytics
- Search debugger
- Developer API
- Twig integration

## Local development

SearchKit is developed as a Composer path repository inside a Craft project. Add the plugin
directory as a repository and require it:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "plugins/Search-Kit"
        }
    ]
}
```

```bash
composer require tahadudhiya/craft-search-kit:@dev
php craft plugin/install search-kit
```

## Tests

```bash
composer test
```

## License

SearchKit is licensed under [The Craft License](LICENSE.md).
