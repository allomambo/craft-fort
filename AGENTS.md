# Fort contributor guide

Fort is a Craft CMS plugin for rate limiting, login monitoring, IP blocking, security headers, and notifications. This file is the entry point for anyone working on the code, human or tool. [README.md](README.md) is the user-facing documentation.

## Stack

- Craft 4 and Craft 5 (`craftcms/cms` `^4.0.0|^5.0.0`), PHP 8.2.
- `Plugin::isCraft5()` gates behavior that only exists on Craft 5.
- Craft 4 Twig parses both branches of an `if`, so a template cannot call a Craft-5-only function even inside a Craft 5 branch. See the comment in `templates/cp/plugin-settings.twig` about `readOnlyNotice()`.

## Layout

| Path | Contents |
|---|---|
| `src/Plugin.php` | Wires components, URL rules, event listeners, and the settings pages. |
| `src/services/` | Feature logic: rate limiting, IP blocks, security events, alerts, notifications, runtime settings, security headers. |
| `src/helpers/` | Small static helpers (IP parsing, PII redaction, display formatting, config overrides). |
| `src/controllers/` | Control Panel controllers. |
| `src/console/controllers/` | Console commands (`fort/events/...`). |
| `src/models/Settings.php` | Plugin settings and validation rules. |
| `src/records/`, `src/migrations/` | Active records and schema migrations. |
| `templates/` | Control Panel and email templates. |
| `translations/` | `en` and `fr` message files. |
| `resources/` | The `config/fort.php` example shown in the settings UI. |

Keep `Plugin.php` to wiring. Put logic in a service, and put pure functions with no Craft state in a helper.

## Controllers

- `requirePermission('accessPlugin-fort')` runs in `beforeAction()`.
- Every action that changes data also calls `requireCpRequest()`, `requirePostRequest()`, `requireAcceptsJson()`, and `requireAdmin()`, and answers with JSON.
- Read-only pages are open to anyone with the plugin permission.

## Strings

- Every user-facing string goes through `Craft::t('fort', ...)` in PHP or `|t('fort')` in Twig.
- Add the entry to both `translations/en/fort.php` and `translations/fr/fort.php`.
- Log messages (`Craft::warning()` and similar) stay untranslated.

## Schema changes

Update `src/migrations/Install.php`, add a new migration under `src/migrations/`, and bump `Plugin::$schemaVersion`.

## Security invariants

- Values from `config/fort.php` bypass `Settings::rules()`, so runtime getters clamp them and the webhook URL is checked again on every send.
- URL and IP checks fail closed: anything that cannot be parsed or resolved is refused.
- Security header values are stripped of control characters before they reach the response.
- Request-derived values never render with `|raw`.
- CP and console requests never attribute the current user as the triggering user.
- With `anonymizePii` on, a login is never stored or sent as typed.
- HSTS is never inferred from the request scheme.

## Checks

Run `composer install` in the plugin root first, then:

| Command | Purpose |
|---|---|
| `composer test` | PHPUnit. |
| `composer check-cs` | Coding standard check (ECS). |
| `composer fix-cs` | Apply coding standard fixes (ECS). |
| `composer phpstan` | Static analysis. |
| `php -l <file>` | Syntax check on a single file. |

Run the checks before pushing.

## Branches and pull requests

- Epic work goes on `epic/<n>-<slug>`.
- Child branches are `fix/<n>-<slug>`, `feat/<n>-<slug>`, or `docs/<n>-<slug>`, and open pull requests into their epic branch.
- Only the epic pull request targets `dev`, the default branch.
- Commit messages start with a conventional prefix (`fix:`, `feat:`, `docs:`) and describe the behavior change.
- Pull request bodies say `Fixes #N` and have a Summary section and a Tested section.
- Releases go through `composer release`. See [scripts/release/README.md](scripts/release/README.md).

## Docs stay in sync

A user-facing change updates [README.md](README.md) in the same pull request.

## Local development

To run Fort from a local checkout inside a DDEV Craft project, see [docs/local-development.md](docs/local-development.md).
