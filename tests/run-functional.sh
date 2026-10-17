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
resultdir=$(mktemp -d "${TMPDIR:-/tmp}/actools-lesson-tests.XXXXXX")
mkdir -p "$resultdir/browser-output"
server_pid=''
stop_server() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    server_pid=''
  fi
}
trap stop_server EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
export SIMPLETEST_BASE_URL="http://127.0.0.1:$port"
export BROWSERTEST_OUTPUT_DIRECTORY="$resultdir/browser-output"
for testclass in LessonBuilderFunctionalTest LessonDeliveryTest LessonEditorialWorkflowTest ProgressFormFunctionalTest; do
  export SIMPLETEST_DB="sqlite://localhost/$resultdir/$testclass.sqlite"
  (
    cd "$webroot"
    exec "$phpbin" -S "127.0.0.1:$port" -t "$webroot" "$webroot/.ht.router.php"
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
    "$phpbin" "$project/vendor/bin/phpunit" -c "$webroot/core/phpunit.xml.dist" \
      "$modulepath/tests/src/Functional/$testclass.php" \
      --log-junit "$resultdir/$testclass.xml"
  )
  stop_server
done
printf 'Functional results and logs: %s\n' "$resultdir"
