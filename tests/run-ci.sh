#!/usr/bin/env bash
# One local/CI entry point. Dependencies and a disposable base site come first.
set -euo pipefail
: "${ACTOOLS_TEST_PROJECT:?Set ACTOOLS_TEST_PROJECT to the disposable Drupal Composer project}"
suite=${1:-all}
case "$suite" in all|unit|kernel|functional|browser) ;; *) printf '%s\n' 'Usage: tests/run-ci.sh [all|unit|kernel|functional|browser]' >&2; exit 2 ;; esac
module_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
project=$(realpath "$ACTOOLS_TEST_PROJECT")
webroot=$(realpath "${ACTOOLS_TEST_WEBROOT:-$project/web}")
phpbin=${ACTOOLS_TEST_PHP:-php}
nodebin=${ACTOOLS_TEST_NODE:-node}
[[ -f "$project/vendor/bin/phpunit" && -f "$webroot/core/phpunit.xml.dist" && "$(realpath "$webroot/modules/custom/actools_lesson")" == "$module_root" ]] || {
  printf '%s\n' 'Expected this module in the disposable Drupal project with matching core-dev dependencies.' >&2
  exit 2
}
if [[ -n ${ACTOOLS_TEST_RESULTS:-} ]]; then
  resultdir=$(realpath -m "$ACTOOLS_TEST_RESULTS")
  mkdir -p "$resultdir"
  [[ -z "$(find "$resultdir" -mindepth 1 -maxdepth 1 -print -quit)" ]] || { printf '%s\n' 'Use an empty test-results directory.' >&2; exit 2; }
else
  resultdir=$(mktemp -d "${TMPDIR:-/tmp}/actools-lesson-ci.XXXXXX")
fi
trap 'printf "CI results and logs: %s\n" "$resultdir"' EXIT
mkdir -p "$resultdir/browser-output"
# PHPUnit starts isolated tests with PHP_BINARY, without the parent's CLI -d
# arguments. An inherited scan directory applies these startup settings there too.
mkdir -p "$resultdir/php-ini"
cat > "$resultdir/php-ini/limits.ini" <<'INI'
memory_limit=768M
max_input_vars=4096
post_max_size=128M
upload_max_filesize=20M
display_errors=0
log_errors=1
INI
# An empty entry preserves PHP's compiled-in scan directory; retain any explicit
# caller-provided entries as well, including extension configuration.
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$resultdir/php-ini"
"$phpbin" -r 'echo json_encode(["php" => PHP_VERSION, "binary" => PHP_BINARY, "loaded_ini" => php_ini_loaded_file(), "scanned_ini" => php_ini_scanned_files(), "settings" => array_intersect_key(ini_get_all(null, false), array_flip(["memory_limit", "max_input_vars", "post_max_size", "upload_max_filesize", "display_errors", "log_errors"]))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";' > "$resultdir/php-settings.json"
export BROWSERTEST_OUTPUT_DIRECTORY="$resultdir/browser-output"
export SIMPLETEST_DB="sqlite://localhost/$resultdir/kernel.sqlite"
"$phpbin" --version > "$resultdir/runtime.txt"
cp "$project/composer.lock" "$resultdir/drupal-composer.lock"
php_args=(-d memory_limit=768M -d max_input_vars=4096 -d post_max_size=128M -d upload_max_filesize=20M -d display_errors=0 -d log_errors=1)
run_phpunit() {
  local kind=$1
  (
    cd "$project"
    "$phpbin" "${php_args[@]}" vendor/bin/phpunit -c "$webroot/core/phpunit.xml.dist" \
      "$module_root/tests/src/$kind" --log-junit "$resultdir/${kind,,}.xml"
  ) 2>&1 | tee "$resultdir/${kind,,}.log"
}
if [[ $suite == all || $suite == unit ]]; then run_phpunit Unit; fi
if [[ $suite == all || $suite == kernel ]]; then run_phpunit Kernel; fi
if [[ $suite == all || $suite == functional ]]; then
  ACTOOLS_TEST_RESULTS="$resultdir/functional" bash "$module_root/tests/run-functional.sh" 2>&1 | tee "$resultdir/functional.log"
fi
if [[ $suite == all || $suite == browser ]]; then
  DRUPAL_ROOT="$webroot" ACTOOLS_TEST_BROWSER_OUTPUT="$resultdir/browser" \
    "$nodebin" "$module_root/tests/browser/run.mjs" 2>&1 | tee "$resultdir/browser.log"
fi
