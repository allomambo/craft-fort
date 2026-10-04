# Local development

How to run Fort from a local checkout inside a DDEV Craft project, so the site always runs the branch you have checked out.

## Mount the checkout into DDEV

DDEV projects that use Mutagen do not see a Composer path-repository symlink that points to a directory outside the project. Instead, mount the checkout over the installed package with a local overlay that stays out of the project's git history.

Create `.ddev/docker-compose.fort.yaml` in the Craft project:

```yaml
#ddev-silent-no-warn
services:
    web:
        volumes:
            - "/path/to/craft-fort:/var/www/html/cms/vendor/allomambo/craft-fort"
```

Replace `/path/to/craft-fort` with the absolute path of your Fort checkout, then restart:

```bash
ddev restart
```

From then on the site runs whichever branch the checkout has. Switch branches in the checkout and reload.

## Notes

- Keep the overlay out of the project's git. Do not commit `.ddev/docker-compose.fort.yaml`.
- The path inside the container depends on the project's Composer root. The example assumes it is `cms/`, so the package lives at `/var/www/html/cms/vendor/allomambo/craft-fort`. Adjust the target if your Composer root differs.
- Fort must already be installed in the project with `composer require allomambo/craft-fort`, so that the target directory exists and Craft knows about the plugin.
- If templates look stale after switching branches, run `ddev craft clear-caches/all`.
- A schema change needs a migration run: `ddev craft up`.
- Installing the plugin's dev dependencies (`composer install` in the checkout) creates a `vendor/` directory inside the mounted directory. The project ignores it, because it autoloads the plugin from `src/`.

## Alternative: Composer path repository

Projects that do not use Mutagen can point Composer at the checkout instead. In the project's `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "/path/to/craft-fort", "options": { "symlink": true } }
  ]
}
```

Then require the plugin with `composer require allomambo/craft-fort:@dev`. Composer symlinks the package to the checkout, so no DDEV overlay is needed.

## Running the checks

Run the plugin's checks from the checkout, not from the Craft project. The commands are listed in [AGENTS.md](../AGENTS.md#checks).
