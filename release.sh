#!/usr/bin/env bash
# Local release of the GitHub line of mod_agora: bump + commit + tag.
# The push is manual (one printed command); pushing the tag triggers
# the Actions workflow that builds the ZIP and publishes the Release.
#
# IMPORTANT: this line is versioned INDEPENDENTLY of GitLab. version.php
# belongs to this repository: any code sync from GitLab (snapshot rsync)
# must exclude version.php, or it will overwrite the bump.
#
# Run on a clean master:
#   ./release.sh rc        # release candidate: tag vX.Y.Z-rc.N, maturity RC
#   ./release.sh stable    # stable release: tag vX.Y.Z, maturity STABLE
#
# The logic mirrors tools/release.sh of the GitLab repository:
#   1. $plugin->version = YYYYMMDDNN: NN is the intra-day counter; lowering
#      is forbidden — Moodle compares versions on upgrade.
#   2. The tag derives from the BASE of the latest v* tag; rc.N is the count
#      of existing vBASE-rc.* tags plus one.
#   3. A stable release requires at least one rc for its BASE; rerunning
#      with an existing tag refuses.
#   4. If the commit fails, the bump is rolled back.
set -euo pipefail

MODE="${1:?rc|stable}"
case "$MODE" in
    rc|stable) ;;
    *) echo "usage: ./release.sh rc|stable" >&2; exit 1 ;;
esac

cd "$(dirname "$0")"

FILE=version.php
TODAY=$(date +%Y%m%d)

if ! git diff --quiet -- "$FILE"; then
    echo "$FILE has uncommitted changes — deal with them first" >&2
    exit 1
fi

CUR=$(sed -nE 's/^\$plugin->version *= *([0-9]+);.*/\1/p' "$FILE")
if [ -z "$CUR" ]; then
    echo "could not find \$plugin->version in $FILE" >&2
    exit 1
fi
if [ $((CUR / 100)) -eq "$TODAY" ]; then
    VERSION=$((CUR + 1))
else
    VERSION=$((TODAY * 100))
fi

# BASE is the X.Y.Z of the latest v* tag (with or without the -rc.N suffix).
LAST=$(git tag -l 'v*' --sort=-v:refname | head -1 || true)
BASE=""
if [[ "$LAST" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)(-rc\.[0-9]+)?$ ]]; then
    BASE="${BASH_REMATCH[1]}"
else
    BASE="1.0.0"
fi

if [ "$MODE" = rc ]; then
    RC_N=$(( $(git tag -l "v${BASE}-rc.*" | wc -l) + 1 ))
    TAG="v${BASE}-rc.${RC_N}"
    RELEASE_STR="${BASE}-rc.${RC_N} (Build: ${TODAY})"
    MATURITY="MATURITY_RC"
else
    if [ "$(git tag -l "v${BASE}" | wc -l)" -gt 0 ]; then
        echo "tag v${BASE} already exists — the stable release for this BASE is out," >&2
        echo "accumulate rc's for the next version first" >&2
        exit 1
    fi
    if [ "$(git tag -l "v${BASE}-rc.*" | wc -l)" -eq 0 ]; then
        echo "no rc for v${BASE} yet — run an rc first" >&2
        exit 1
    fi
    TAG="v${BASE}"
    RELEASE_STR="${BASE} (Build: ${TODAY})"
    MATURITY="MATURITY_STABLE"
fi

if [ "$(git tag -l "$TAG" | wc -l)" -gt 0 ]; then
    echo "tag $TAG already exists — do not repeat yourself" >&2
    exit 1
fi
if [ "$CUR" -ge "$VERSION" ]; then
    echo "$FILE is already at $CUR — no bump needed" >&2
    exit 0
fi

sed -i -E "s/^\\\$plugin->version *= *[0-9]+;/\$plugin->version   = ${VERSION};/" "$FILE"
sed -i -E "s/^\\\$plugin->maturity *= *MATURITY_[A-Z]+;/\$plugin->maturity  = ${MATURITY};/" "$FILE"
sed -i -E "s/^\\\$plugin->release *= *'[^']*';/\$plugin->release   = '${RELEASE_STR}';/" "$FILE"

if ! git commit -q -m "Release ${TAG}" -- "$FILE"; then
    git checkout -q -- "$FILE"
    echo "commit failed — bump rolled back, sort out git and retry" >&2
    exit 1
fi
git tag -a "$TAG" -m "Release ${TAG}"

echo "version.php: version=${VERSION} release='${RELEASE_STR}' maturity=${MATURITY}"
echo "commit: $(git log --oneline -1)"
echo "tag: ${TAG}"
echo
echo "Left to do manually — push the commit and the tag (the tag triggers Actions → Release):"
echo "  git push origin master ${TAG}"
