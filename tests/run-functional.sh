#!/usr/bin/env bash
# Run only against a disposable local Drupal development installation.
set -euo pipefail
: "${ACTOOLS_TEST_PROJECT:?Set ACTOOLS_TEST_PROJECT to the disposable Drupal Composer project}"
project=$(realpath "$ACTOOLS_TEST_PROJECT")
webroot=$(realpath "${ACTOOLS_TEST_WEBROOT:-$project/web}")
phpbin=${ACTOOLS_TEST_PHP:-php}
port=${ACTOOLS_TEST_PORT:-8096}
modulepath="$webroot/modules/custom/actools_lesson"
[[ $port =~ ^[0-9]+$ ]] || { printf '%s\n' 'ACTOOLS_TEST_PORT must be numeric.' >&2; exit 2; }
[[ -f "$webroot/.ht.router.php" && -f "$webroot/core/phpunit.xml.dist" && -f "$project/vendor/bin/phpunit" && -f "$modulepath/actools_lesson.info.yml" ]] || {
  printf '%s\n' 'Expected a Drupal development project, core-dev dependencies and actools_lesson under modules/custom.' >&2
  exit 2
}
if [[ -n ${ACTOOLS_TEST_RESULTS:-} ]]; then
  resultdir=$(realpath -m "$ACTOOLS_TEST_RESULTS")
  mkdir -p "$resultdir"
  [[ -z "$(find "$resultdir" -mindepth 1 -maxdepth 1 -print -quit)" ]] || { printf '%s\n' 'Use an empty Functional results directory.' >&2; exit 2; }
else
  resultdir=$(mktemp -d "${TMPDIR:-/tmp}/actools-lesson-tests.XXXXXX")
fi
mkdir -p "$resultdir/browser-output"
# Isolated PHPUnit children execute PHP_BINARY without inheriting CLI -d flags.
# Keep these startup settings in an inherited INI scan directory instead.
mkdir -p "$resultdir/php-ini"
cat > "$resultdir/php-ini/limits.ini" <<'INI'
memory_limit=768M
max_input_vars=4096
post_max_size=128M
upload_max_filesize=20M
display_errors=0
log_errors=1
INI
# Preserve explicit scan directories and PHP's default directory when unset.
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$resultdir/php-ini"
"$phpbin" -r 'echo json_encode(["php" => PHP_VERSION, "binary" => PHP_BINARY, "loaded_ini" => php_ini_loaded_file(), "scanned_ini" => php_ini_scanned_files(), "settings" => array_intersect_key(ini_get_all(null, false), array_flip(["memory_limit", "max_input_vars", "post_max_size", "upload_max_filesize", "display_errors", "log_errors"]))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";' > "$resultdir/php-settings.json"
php_args=(-d memory_limit=768M -d max_input_vars=4096 -d post_max_size=128M -d upload_max_filesize=20M -d display_errors=0 -d log_errors=1)
mapfile -d '' testfiles < <(find "$modulepath/tests/src/Functional" -type f -name '*Test.php' -print0 | sort -z)
[[ ${#testfiles[@]} -gt 0 ]] || { printf '%s\n' 'No Functional test files found.' >&2; exit 2; }
server_pid=''
stop_server() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    server_pid=''
  fi
}
finish() {
  exit_status=$?
  trap - EXIT
  stop_server
  if [[ $exit_status -ne 0 ]]; then printf 'Functional run failed; retained results: %s\n' "$resultdir" >&2; fi
  exit "$exit_status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
export SIMPLETEST_BASE_URL="http://127.0.0.1:$port"
export BROWSERTEST_OUTPUT_DIRECTORY="$resultdir/browser-output"
for testfile in "${testfiles[@]}"; do
  testclass=${testfile#"$modulepath/tests/src/Functional/"}
  testclass=${testclass%.php}
  testclass=${testclass//\//-}
  export SIMPLETEST_DB="sqlite://localhost/$resultdir/$testclass.sqlite"
  (
    cd "$webroot"
    exec "$phpbin" "${php_args[@]}" -S "127.0.0.1:$port" -t "$webroot" "$webroot/.ht.router.php"
  ) > "$resultdir/$testclass-server.log" 2>&1 &
  server_pid=$!
  ready=false
  for attempt in {1..100}; do
    if ! kill -0 "$server_pid" 2>/dev/null; then
      printf 'Test server exited; inspect %s\n' "$resultdir/$testclass-server.log" >&2
      exit 1
    fi
    if "$phpbin" -r '$socket = @fsockopen("127.0.0.1", (int) $argv[1], $errno, $error, 0.2); if (!$socket) { exit(1); } fclose($socket);' "$port"; then
      ready=true
      break
    fi
    sleep 0.1
  done
  if [[ $ready != true ]]; then
    printf 'Test server did not become ready; inspect %s\n' "$resultdir/$testclass-server.log" >&2
    exit 1
  fi
  printf 'Running %s\n' "$testclass"
  (
    cd "$project"
    "$phpbin" "${php_args[@]}" "$project/vendor/bin/phpunit" -c "$webroot/core/phpunit.xml.dist" \
      "$testfile" \
      --log-junit "$resultdir/$testclass.xml"
  )
  stop_server
done
printf 'Functional results and logs: %s\n' "$resultdir"
