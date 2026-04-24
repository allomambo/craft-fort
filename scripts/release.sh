#!/bin/bash

set -euo pipefail

MAIN_BRANCH="main"
DEV_BRANCH="dev"

# ---- working directory ----
if [[ ! -f "composer.json" ]]; then
  echo "❌ composer.json not found. Run this script from the project root."
  exit 1
fi

# ---- argument parsing ----
DRY_RUN=0
BUMP=""
for arg in "$@"; do
  case "$arg" in
    --dry-run|-n)
      DRY_RUN=1
      ;;
    patch|minor|major|alpha|beta|stable)
      BUMP="$arg"
      ;;
    *)
      echo "❌ Unknown argument: $arg"
      echo "Usage: $0 [--dry-run] [patch|minor|major|alpha|beta|stable]"
      exit 1
      ;;
  esac
done

if [[ -z "$BUMP" ]]; then
  echo "Usage: $0 [--dry-run] [patch|minor|major|alpha|beta|stable]"
  exit 1
fi

# ---- helpers ----

# Run a command, or print it as "[dry-run] ..." when DRY_RUN=1.
# Use this for any command that mutates git, gh, or files.
run() {
  if [[ "$DRY_RUN" == "1" ]]; then
    local display=""
    for a in "$@"; do
      if [[ "$a" =~ [[:space:]] ]]; then
        display+=" \"$a\""
      else
        display+=" $a"
      fi
    done
    echo "  [dry-run]${display}"
  else
    "$@"
  fi
}

update_composer_version() {
  local new_version="$1"
  if [[ "$DRY_RUN" == "1" ]]; then
    echo "  [dry-run] jq \".version = \\\"${new_version}\\\"\" composer.json > composer.json.tmp && mv composer.json.tmp composer.json"
  else
    jq ".version = \"${new_version}\"" composer.json > composer.json.tmp && mv composer.json.tmp composer.json
  fi
}

# Prepend a new section to CHANGELOG.md (stable releases only).
# In dry-run mode, prints the proposed section to stdout instead of writing.
prepend_changelog() {
  local version="$1"
  local since_tag="$2"
  local today
  today=$(date +%Y-%m-%d)

  local entries
  if [[ -z "$since_tag" ]]; then
    entries=$(git log --pretty=format:"- %s" --no-merges)
  else
    entries=$(git log --pretty=format:"- %s" --no-merges "${since_tag}..HEAD")
  fi

  if [[ -z "$entries" ]]; then
    entries="- (no commits since ${since_tag:-repository start})"
  fi

  local section="## ${version} - ${today}

### Changed
${entries}
"

  if [[ "$DRY_RUN" == "1" ]]; then
    echo ""
    echo "  ----- Proposed CHANGELOG.md prepend (since ${since_tag:-repository start}) -----"
    while IFS= read -r line; do
      echo "  ${line}"
    done <<< "${section}"
    echo "  -----------------------------------------------------------------"
    echo ""
    return
  fi

  awk -v sect="$section" '
    NR==1 { print; print ""; print sect; next }
    NR==2 && /^$/ { next }
    { print }
  ' CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md

  echo "✏️  Opening ${EDITOR:-vi} for CHANGELOG.md (save & quit to continue, :cq in vim to abort)..."
  ${EDITOR:-vi} CHANGELOG.md
}

# ---- preflight: tools ----
if ! command -v jq &> /dev/null; then
  echo "❌ jq is not installed."
  echo "Install it with:"
  echo "  brew install jq      # macOS"
  echo "  sudo apt install jq  # Ubuntu/Debian"
  exit 1
fi

if ! command -v gh &> /dev/null; then
  echo "❌ GitHub CLI (gh) is not installed."
  echo "Install it with:"
  echo "  brew install gh      # macOS"
  echo "  sudo apt install gh  # Ubuntu/Debian"
  echo "  choco install gh     # Windows"
  exit 1
fi

if ! gh auth status &> /dev/null; then
  echo "❌ GitHub CLI is not authenticated. Run: gh auth login"
  exit 1
fi

# ---- read + parse current version ----
echo "🔢 Reading current version from composer.json..."
VERSION=$(jq -r '.version' composer.json)
echo "Current version: $VERSION"

if [[ $VERSION =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)(-([a-z]+)\.([0-9]+))?$ ]]; then
  MAJOR="${BASH_REMATCH[1]}"
  MINOR="${BASH_REMATCH[2]}"
  PATCH="${BASH_REMATCH[3]}"
  PRERELEASE_TYPE="${BASH_REMATCH[5]:-}"
  PRERELEASE_NUM="${BASH_REMATCH[6]:-}"
else
  echo "❌ Invalid version format: $VERSION"
  exit 1
fi

# ---- compute new version ----
case $BUMP in
  patch)
    if [[ -n "$PRERELEASE_TYPE" ]]; then
      echo "❌ Cannot do a patch release from a prerelease version. Release a stable version first or continue with alpha/beta."
      exit 1
    fi
    PATCH=$((PATCH + 1))
    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
    ;;
  minor)
    if [[ -n "$PRERELEASE_TYPE" ]]; then
      echo "❌ Cannot do a minor release from a prerelease version. Release a stable version first or continue with alpha/beta."
      exit 1
    fi
    MINOR=$((MINOR + 1))
    PATCH=0
    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
    ;;
  major)
    if [[ -n "$PRERELEASE_TYPE" ]]; then
      echo "❌ Cannot do a major release from a prerelease version. Release a stable version first or continue with alpha/beta."
      exit 1
    fi
    MAJOR=$((MAJOR + 1))
    MINOR=0
    PATCH=0
    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
    ;;
  alpha)
    if [[ "$PRERELEASE_TYPE" == "alpha" ]]; then
      PRERELEASE_NUM=$((PRERELEASE_NUM + 1))
      NEW_VERSION="$MAJOR.$MINOR.$PATCH-alpha.$PRERELEASE_NUM"
    elif [[ "$PRERELEASE_TYPE" == "beta" ]]; then
      echo "❌ Cannot go from beta back to alpha. Release a stable version first."
      exit 1
    else
      PATCH=$((PATCH + 1))
      NEW_VERSION="$MAJOR.$MINOR.$PATCH-alpha.1"
    fi
    ;;
  beta)
    if [[ "$PRERELEASE_TYPE" == "alpha" ]]; then
      NEW_VERSION="$MAJOR.$MINOR.$PATCH-beta.1"
    elif [[ "$PRERELEASE_TYPE" == "beta" ]]; then
      PRERELEASE_NUM=$((PRERELEASE_NUM + 1))
      NEW_VERSION="$MAJOR.$MINOR.$PATCH-beta.$PRERELEASE_NUM"
    else
      PATCH=$((PATCH + 1))
      NEW_VERSION="$MAJOR.$MINOR.$PATCH-beta.1"
    fi
    ;;
  stable)
    if [[ -z "$PRERELEASE_TYPE" ]]; then
      echo "❌ Already on a stable version ($VERSION). Use patch/minor/major to bump."
      exit 1
    fi
    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
    ;;
  *)
    echo "❌ Invalid bump type: $BUMP"
    exit 1
    ;;
esac

NEW_TAG="v$NEW_VERSION"
IS_PRERELEASE=0
if [[ "$NEW_VERSION" =~ -(alpha|beta)\. ]]; then
  IS_PRERELEASE=1
fi

# ---- preflight: repo state ----
echo "🔍 Checking working directory..."
if [[ -n $(git status --porcelain) ]]; then
  echo "❌ Please commit or stash your changes before releasing."
  exit 1
fi

if git rev-parse "$NEW_TAG" >/dev/null 2>&1; then
  echo "❌ Tag $NEW_TAG already exists locally. Aborting."
  exit 1
fi

if git ls-remote --tags --exit-code origin "$NEW_TAG" >/dev/null 2>&1; then
  echo "❌ Tag $NEW_TAG already exists on origin. Aborting."
  exit 1
fi

# ---- summary ----
echo ""
if [[ "$DRY_RUN" == "1" ]]; then
  echo "🧪 DRY RUN — no changes will be made."
fi
echo "🔖 New version: $NEW_VERSION"
echo "📋 Current version: $VERSION"
if [[ "$IS_PRERELEASE" == "1" ]]; then
  echo "📦 Type: prerelease (no CHANGELOG update; tagged on $DEV_BRANCH)"
else
  echo "📦 Type: stable (CHANGELOG update; release branch -> $MAIN_BRANCH; back-merge to $DEV_BRANCH)"
fi
echo ""

# ---- confirmation (skipped in dry-run) ----
if [[ "$DRY_RUN" != "1" ]]; then
  read -p "❓ Proceed with this release? (y/n): " -n 1 -r
  echo ""
  if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Release cancelled."
    exit 0
  fi
fi

echo "✅ Proceeding with $NEW_TAG..."
echo ""

# ---- ensure dev is up to date ----
echo "🚀 Fetching origin and updating $DEV_BRANCH..."
run git fetch --tags origin
run git checkout "$DEV_BRANCH"
run git pull origin "$DEV_BRANCH"

# ---- compute previous tags (read-only, used for release notes + changelog scope) ----
PREV_TAG=$(git describe --tags --abbrev=0 2>/dev/null || true)
PREV_STABLE_TAG=$(git tag --list 'v*' --sort=-version:refname | grep -v -- '-' | head -n 1 || true)

# ---- prerelease vs stable flow ----
if [[ "$IS_PRERELEASE" == "1" ]]; then
  echo "📦 Creating prerelease $NEW_TAG on $DEV_BRANCH..."

  echo "✏️  Updating composer.json version..."
  update_composer_version "$NEW_VERSION"

  echo "📦 Committing and tagging..."
  run git add composer.json
  run git commit -m "Release $NEW_TAG"
  run git tag -a "$NEW_TAG" -m "Release $NEW_TAG"

  echo "⬆️  Pushing $DEV_BRANCH and tag atomically..."
  run git push --atomic origin "$DEV_BRANCH" "$NEW_TAG"

else
  RELEASE_BRANCH="release/$NEW_TAG"

  echo "🌱 Creating release branch: $RELEASE_BRANCH"
  run git checkout -b "$RELEASE_BRANCH"

  echo "✏️  Updating composer.json version..."
  update_composer_version "$NEW_VERSION"

  echo "📝 Updating CHANGELOG.md (stable release)..."
  prepend_changelog "$NEW_VERSION" "$PREV_STABLE_TAG"

  echo "📦 Committing and tagging release..."
  run git add composer.json CHANGELOG.md
  run git commit -m "Release $NEW_TAG"
  run git tag -a "$NEW_TAG" -m "Release $NEW_TAG"

  echo "⬆️  Merging release branch into $MAIN_BRANCH..."
  run git checkout "$MAIN_BRANCH"
  run git pull origin "$MAIN_BRANCH"
  run git merge --no-ff "$RELEASE_BRANCH" -m "Merge release $NEW_TAG"

  echo "⬆️  Pushing $MAIN_BRANCH and tag atomically..."
  run git push --atomic origin "$MAIN_BRANCH" "$NEW_TAG"

  echo "⬆️  Back-merging into $DEV_BRANCH..."
  run git checkout "$DEV_BRANCH"
  run git merge --no-ff "$RELEASE_BRANCH" -m "Merge release $NEW_TAG into $DEV_BRANCH"
  run git push origin "$DEV_BRANCH"
fi

# ---- generate release notes (commits since previous tag of any kind) ----
echo "📝 Generating GitHub release notes (since ${PREV_TAG:-repository start})..."
if [[ -z "$PREV_TAG" ]]; then
  RELEASE_NOTES=$(git log --pretty=format:"- %s" --no-merges)
else
  if [[ "$DRY_RUN" == "1" ]]; then
    RELEASE_NOTES=$(git log --pretty=format:"- %s" --no-merges "${PREV_TAG}..HEAD")
  else
    RELEASE_NOTES=$(git log --pretty=format:"- %s" --no-merges "${PREV_TAG}..${NEW_TAG}")
  fi
fi

# ---- create gh release ----
echo "🚀 Creating GitHub release..."
GH_FLAGS=()
if [[ "$IS_PRERELEASE" == "1" ]]; then
  GH_FLAGS+=("--prerelease")
fi

if [[ "$DRY_RUN" == "1" ]]; then
  echo "  [dry-run] gh release create $NEW_TAG --title $NEW_TAG ${GH_FLAGS[*]:-} --notes <see below>"
  echo ""
  echo "  ----- GitHub release notes preview -----"
  if [[ -z "$RELEASE_NOTES" ]]; then
    echo "  (no commits)"
  else
    while IFS= read -r line; do
      echo "  ${line}"
    done <<< "${RELEASE_NOTES}"
  fi
  echo "  ----------------------------------------"
else
  # Capture stdout (the release URL) and detach stdin so gh's TUI stack
  # does not probe the terminal for OSC 11 / cursor position. Without this,
  # the terminal's escape-sequence replies leak into the visible output.
  RELEASE_URL=$(gh release create "$NEW_TAG" --title "$NEW_TAG" --notes "$RELEASE_NOTES" "${GH_FLAGS[@]}" </dev/null)
  echo "  $RELEASE_URL"
fi

# ---- cleanup release branch (stable only) ----
if [[ "$IS_PRERELEASE" == "0" ]]; then
  echo "🧹 Deleting release branch..."
  run git checkout "$DEV_BRANCH"
  run git branch -d "$RELEASE_BRANCH"
  if [[ "$DRY_RUN" == "1" ]]; then
    echo "  [dry-run] git push origin --delete $RELEASE_BRANCH (if remote exists)"
  else
    if git ls-remote --exit-code --heads origin "$RELEASE_BRANCH" &>/dev/null; then
      git push origin --delete "$RELEASE_BRANCH"
    else
      echo "ℹ️  Remote branch $RELEASE_BRANCH does not exist, skipping remote delete."
    fi
  fi
fi

# ---- footer ----
echo ""
if [[ "$DRY_RUN" == "1" ]]; then
  echo "🧪 [dry-run] No changes were made."
  echo "🧪 [dry-run] Run again without --dry-run to perform the release."
else
  echo "✅ Released $NEW_TAG"
fi
