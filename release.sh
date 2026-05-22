#!/bin/bash
#
# Tag and push a release.
#
# CI (.github/workflows/release.yml) handles the actual GitHub Release
# creation on tag push. This script's only job is to verify CHANGELOG
# matches the version, ensure the working tree is clean, and tag.
#
# kyte-php is distributed via Composer (packagist.org), which auto-
# discovers tags from GitHub. Once the tag exists upstream, customers
# can `composer require keyqcloud/kyte-php:^X.Y` to pull it.
#
# Usage:  ./release.sh 4.4.0

set -e

print_error()   { printf "\033[1;31m%s\033[0m\n" "$1"; }
print_success() { printf "\033[1;32m%s\033[0m\n" "$1"; }

if [ "$#" -ne 1 ]; then
    print_error "Usage: ./release.sh <version>"
    print_error "Example: ./release.sh 4.4.0"
    exit 1
fi

VERSION="$1"

# Sanity-check CHANGELOG has the new version at the top.
CHANGELOG_VERSION=$(awk '/^## /{print $2; exit}' CHANGELOG.md)
if [ "$CHANGELOG_VERSION" != "$VERSION" ]; then
    print_error "Version in CHANGELOG.md ($CHANGELOG_VERSION) does not match $VERSION."
    print_error "Update CHANGELOG.md to add a '## $VERSION' section before releasing."
    exit 1
fi

# Refuse to tag against a dirty tree — would tag unintended state.
if ! git diff-index --quiet HEAD --; then
    print_error "Working tree is not clean. Commit or stash changes first."
    git status --short
    exit 1
fi

# Refuse to tag if we're not on master.
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$CURRENT_BRANCH" != "master" ]; then
    print_error "Releases must be tagged from master. Current branch: $CURRENT_BRANCH"
    exit 1
fi

# Refuse to tag if local master is behind origin.
git fetch origin master --quiet
LOCAL=$(git rev-parse master)
REMOTE=$(git rev-parse origin/master)
if [ "$LOCAL" != "$REMOTE" ]; then
    print_error "Local master is not in sync with origin/master. Push or pull first."
    exit 1
fi

# Refuse to tag if the tag already exists locally or upstream.
if git rev-parse "v$VERSION" >/dev/null 2>&1; then
    print_error "Tag v$VERSION already exists locally."
    exit 1
fi
if git ls-remote --tags origin "v$VERSION" 2>/dev/null | grep -q "v$VERSION"; then
    print_error "Tag v$VERSION already exists on origin."
    exit 1
fi

print_success "Tagging v$VERSION..."
git tag "v$VERSION"
git push origin "v$VERSION"

print_success ""
print_success "Tagged v$VERSION and pushed."
print_success "GitHub Actions will now extract release notes from CHANGELOG.md"
print_success "and create the GitHub Release."
print_success "Watch: https://github.com/keyqcloud/kyte-php/actions"
print_success ""
print_success "Once the GitHub Release is published, Packagist will auto-detect"
print_success "the new tag within minutes. Customers pulling via Composer with"
print_success "a constraint matching v$VERSION (e.g., ^${VERSION%%.*}.${VERSION#*.}"
print_success "or ^${VERSION%%.*}.${VERSION#*.}.0) will receive it on next update."
