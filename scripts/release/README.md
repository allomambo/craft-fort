# Release tooling (internal)

Internal documentation for the `scripts/release.sh` automation.

The corresponding shipped Composer package excludes this directory via `.gitattributes` (`/scripts export-ignore`), so end-users never see it.

---

## TL;DR

```bash
# Stable releases (writes CHANGELOG, merges via release branch into main, back-merges to dev)
composer release patch
composer release minor
composer release major

# Prereleases (no CHANGELOG, tagged on dev directly)
composer release alpha
composer release beta

# Promote current alpha/beta to stable (no version bump, just strips the suffix)
composer release stable

# Preview without touching anything
composer release -- patch --dry-run
composer release -- alpha --dry-run

# Equivalent direct invocations (no Composer)
./scripts/release.sh patch
./scripts/release.sh patch --dry-run
```

The `--` between `composer release` and the bump type is **only required when passing flags** like `--dry-run`. Plain bumps (`composer release patch`) do not need it.

---

## Prerequisites

The script aborts early if any of these are missing:

| Tool                    | Why                                                                   | Install                            |
| ----------------------- | --------------------------------------------------------------------- | ---------------------------------- |
| `gh` (authenticated)    | Creates the GitHub Release                                            | `brew install gh && gh auth login` |
| `jq`                    | Reads/writes `composer.json` version field                            | `brew install jq`                  |
| Clean git working tree  | Prevents accidentally bundling unrelated work into the release commit | Commit or stash first              |
| Push access to `origin` | Pushes branches and tags                                              | Standard repo permissions          |

You also need to be on a checkout where:

- `dev` and `main` branches exist locally and track `origin/dev` / `origin/main`.
- Your local `dev` is up-to-date enough that `git pull` succeeds without conflicts.

---

## Branch model

The script assumes Git Flow-lite:

- **`dev`** — integration branch. All day-to-day work lands here.
- **`main`** — stable history. Only updated by stable releases.
- **`release/v<X.Y.Z>`** — short-lived. Created from `dev`, merged to `main`, back-merged to `dev`, then deleted.

Branch names are configurable at the top of `scripts/release.sh` (`MAIN_BRANCH`, `DEV_BRANCH`).

---

## Release types

### Stable (`patch` | `minor` | `major`)

Full release flow. Cannot be invoked while currently on a prerelease version (use `stable` first to drop the suffix).

1. Bumps `composer.json` version.
2. Creates `release/v<NEW>` branch from `dev`.
3. Prepends a new section to `CHANGELOG.md` listing commits since the previous **stable** tag (intentionally excluding any intermediate alpha/beta tags so the entry consolidates the whole prerelease cycle).
4. Opens `$EDITOR` so you can refine wording, recategorize bullets (`### Added` / `### Fixed` / `### Removed` / `### Security`), and drop noise.
5. Commits `composer.json` + `CHANGELOG.md`, creates an annotated tag `v<NEW>`.
6. Merges `release/v<NEW>` into `main` (no-ff).
7. Back-merges into `dev` (no-ff).
8. Pushes branches + tag atomically.
9. Creates the GitHub Release (no `--prerelease` flag).
10. Deletes the release branch locally and on origin.

### Prerelease (`alpha` | `beta`)

Cut directly on `dev`. Does **not** touch `CHANGELOG.md`.

- From a stable version: bumps patch and starts at `<X.Y.Z+1>-alpha.1` (or `-beta.1`).
- From an existing alpha: increments the alpha counter (`-alpha.N`).
- From an existing beta: increments the beta counter (`-beta.N`).
- Going from beta back to alpha is forbidden.
- Going from alpha to beta resets at `-beta.1` on the same `X.Y.Z`.

The GitHub Release is created with `--prerelease` so it's clearly marked.

### Promotion (`stable`)

Strips the `-alpha.N` / `-beta.N` suffix from the current version and runs the full **stable** flow on that version. The CHANGELOG entry covers everything since the previous stable tag.

---

## Version state machine

```
1.2.3 ──patch──▶ 1.2.4
      ──minor──▶ 1.3.0
      ──major──▶ 2.0.0
      ──alpha──▶ 1.2.4-alpha.1 ──alpha──▶ 1.2.4-alpha.2 ──beta──▶ 1.2.4-beta.1 ──beta──▶ 1.2.4-beta.2 ──stable──▶ 1.2.4
      ──beta───▶ 1.2.4-beta.1
```

---

## CHANGELOG policy

- **Stable releases write to `CHANGELOG.md`.** One consolidated entry per stable version.
- **Prereleases do not.** Their notes live only in the auto-generated GitHub Release body, marked `--prerelease`.
- The `composer.json` `changelogUrl` points at `CHANGELOG.md` on `main` — keeping prereleases out of it means consumers see a clean, monotonically-increasing list of stable versions.

The `$EDITOR` pause during stable releases is intentional: the auto-generated bullets (commit subjects since the previous stable tag) are a starting point, not the final copy. Edit them to:

- Sort into `### Added` / `### Changed` / `### Fixed` / `### Removed` / `### Security`.
- Drop merge commits, internal refactors, and noise.
- Rewrite cryptic commit subjects into user-facing language.

Save and close the editor to continue the release. Quit without saving (`:cq` in vim) to abort — `set -e` will halt the script before anything is committed.

---

## Dry-run mode

```bash
composer release -- patch --dry-run
```

- All mutating steps (`git add`, `git commit`, `git tag`, `git push`, `gh release create`, `jq` writes) print as `[dry-run] <command>` instead of running.
- Read-only inspection (`git status`, `git tag --list`, current version) still happens, so the preview reflects real repo state.
- For stable releases, the proposed CHANGELOG section is printed to stdout between separator lines so you can sanity-check the bullet list.
- No files are modified, no branches created, no tags written, no remote pushes.

Use this before every real release until the flow is muscle-memory.

---

## Recovery: undoing a half-failed release

If the script dies mid-flow, the repo can be left in any of these states. Pick the matching recovery:

### Tag created locally but not pushed

```bash
git tag -d v<X.Y.Z>
```

### Tag pushed to origin

```bash
git tag -d v<X.Y.Z>
git push origin --delete v<X.Y.Z>
```

### GitHub Release created

```bash
gh release delete v<X.Y.Z> --yes
```

### Release branch left behind

```bash
git branch -D release/v<X.Y.Z>
git push origin --delete release/v<X.Y.Z>   # if it made it to origin
```

### `main` already merged but `dev` back-merge failed

The merge commit on `main` is fine to keep. Re-run the back-merge manually:

```bash
git checkout dev
git pull
git merge --no-ff release/v<X.Y.Z> -m "Merge release v<X.Y.Z> into dev"
git push origin dev
```

### `composer.json` bumped but commit not made

```bash
git checkout -- composer.json CHANGELOG.md
```

---

## Maintaining the script

- `MAIN_BRANCH` / `DEV_BRANCH` constants live at the top of `scripts/release.sh`.
- The script uses `set -euo pipefail` — any failed command halts execution.
- When adding new mutating steps, wrap them in the `run "..."` helper so `--dry-run` keeps working.
- Tag lookups distinguish `PREV_TAG` (any most-recent tag, used for GitHub Release notes scope) from `PREV_STABLE_TAG` (most recent stable, used for CHANGELOG scope).
