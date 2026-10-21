#!/usr/bin/env bash
# Локальный релиз GitHub-линии mod_agora: бамп + коммит + тег. Пуш — руками
# одной напечатанной командой; пуш тега триггерит Actions → Release с ZIP.
#
# ВАЖНО: эта линия версионируется САМОСТОЯТЕЛЬНО, независимо от GitLab.
# version.php здесь — собственность этого репо: синк кода из GitLab
# (rsync снапшота) обязан исключать version.php, иначе сотрёт бамп.
#
# Запускать на чистом мастере:
#   ./release.sh rc        # релиз-кандидат: тег vX.Y.Z-rc.N, maturity RC
#   ./release.sh stable    # стабильный релиз: тег vX.Y.Z, maturity STABLE
#
# Логика — как в GitLab tools/release.sh:
#   1. $plugin->version = YYYYMMDDNN: NN — счётчик внутри дня; понижать
#      нельзя — Moodle сравнивает версии при апгрейде.
#   2. Тег от BASE последнего тега v*; rc.N — число тегов vBASE-rc.* плюс один.
#   3. Stable без хотя бы одного rc для этой BASE не выпускается; повторный
#      прогон с существующим тегом отказывает.
#   4. Коммит не удался — бамп откатывается обратно.
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
    echo "$FILE имеет незакоммиченные правки — сначала разберись с ними" >&2
    exit 1
fi

CUR=$(sed -nE 's/^\$plugin->version *= *([0-9]+);.*/\1/p' "$FILE")
if [ -z "$CUR" ]; then
    echo "не нашли \$plugin->version в $FILE" >&2
    exit 1
fi
if [ $((CUR / 100)) -eq "$TODAY" ]; then
    VERSION=$((CUR + 1))
else
    VERSION=$((TODAY * 100))
fi

# BASE — X.Y.Z последнего тега v* (с суффиксом -rc.N или без).
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
        echo "тег v${BASE} уже существует — стабильный релиз для этой BASE выпущен," >&2
        echo "сначала накопите rc для следующей версии" >&2
        exit 1
    fi
    if [ "$(git tag -l "v${BASE}-rc.*" | wc -l)" -eq 0 ]; then
        echo "для v${BASE} нет ни одного rc — сначала прогоните rc" >&2
        exit 1
    fi
    TAG="v${BASE}"
    RELEASE_STR="${BASE} (Build: ${TODAY})"
    MATURITY="MATURITY_STABLE"
fi

if [ "$(git tag -l "$TAG" | wc -l)" -gt 0 ]; then
    echo "тег $TAG уже существует — не повторяйся" >&2
    exit 1
fi
if [ "$CUR" -ge "$VERSION" ]; then
    echo "$FILE уже на $CUR — бамп не нужен" >&2
    exit 0
fi

sed -i -E "s/^\\\$plugin->version *= *[0-9]+;/\$plugin->version   = ${VERSION};/" "$FILE"
sed -i -E "s/^\\\$plugin->maturity *= *MATURITY_[A-Z]+;/\$plugin->maturity  = ${MATURITY};/" "$FILE"
sed -i -E "s/^\\\$plugin->release *= *'[^']*';/\$plugin->release   = '${RELEASE_STR}';/" "$FILE"

if ! git commit -q -m "Release ${TAG}" -- "$FILE"; then
    git checkout -q -- "$FILE"
    echo "коммит не удался — бамп откачен, разберись с git и повтори" >&2
    exit 1
fi
git tag -a "$TAG" -m "Release ${TAG}"

echo "version.php: version=${VERSION} release='${RELEASE_STR}' maturity=${MATURITY}"
echo "коммит: $(git log --oneline -1)"
echo "тег: ${TAG}"
echo
echo "Осталось руками — пуш коммита и тега (тег триггерит Actions → Release):"
echo "  git push origin master ${TAG}"
