#!/usr/bin/env bash
# Create only a new, disposable Drupal project. Never targets an existing site.
set -euo pipefail
: "${ACTOOLS_TEST_PROJECT:?Set ACTOOLS_TEST_PROJECT to a new disposable project path}"
module_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
project=$(realpath -m "$ACTOOLS_TEST_PROJECT")
phpbin=${ACTOOLS_TEST_PHP:-php}
composerbin=${ACTOOLS_TEST_COMPOSER:-composer}
version=${ACTOOLS_TEST_DRUPAL_VERSION:-11.4.10}
[[ $version =~ ^11\.4\.[0-9]+$ ]] || { printf '%s\n' 'Use an explicit Drupal 11.4.x patch version.' >&2; exit 2; }
if [[ -e "$project" ]]; then
  [[ -d "$project" && ! -L "$ACTOOLS_TEST_PROJECT" && -z "$(find "$project" -mindepth 1 -maxdepth 1 -print -quit)" ]] || {
    printf '%s\n' 'Bootstrap refuses an existing nonempty project. Supply a new disposable path.' >&2
    exit 2
  }
fi
[[ "$project" != / && "$project" != "$module_root" ]] || { printf '%s\n' 'Invalid disposable project path.' >&2; exit 2; }
"$phpbin" -r 'if (PHP_VERSION_ID < 80300) { fwrite(STDERR, "PHP 8.3+ is required.\n"); exit(2); } foreach (["curl", "dom", "fileinfo", "gd", "mbstring", "pdo_sqlite", "sqlite3", "xml", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(2); } }'
mkdir -p "$(dirname "$project")"
"$composerbin" create-project --no-interaction --prefer-dist --no-install "drupal/recommended-project:$version" "$project"
(
  cd "$project"
  # Pin core and core-dev together; retain the generated lock for each run.
  "$composerbin" require --no-interaction --no-update \
    "drupal/core-recommended:$version" "drupal/core-composer-scaffold:$version" \
    "drupal/core-project-message:$version" 'drush/drush:13.8.0'
  "$composerbin" require --no-interaction --dev --no-update "drupal/core-dev:$version"
  for plugin in composer/installers drupal/core-composer-scaffold drupal/core-recipe-unpack drupal/core-project-message symfony/runtime phpstan/extension-installer dealerdirect/phpcodesniffer-composer-installer php-http/discovery tbachert/spi; do
    "$composerbin" config "allow-plugins.$plugin" true
  done
  "$composerbin" update --no-interaction --prefer-dist --no-progress
)
mkdir -p "$project/web/modules/custom" "$project/web/sites/default/files"
ln -s "$module_root" "$project/web/modules/custom/actools_lesson"
state_dir=$(mktemp -d "${TMPDIR:-/tmp}/actools-lesson-site.XXXXXX")
test_password=$("$phpbin" -r 'echo bin2hex(random_bytes(24));')
(
  cd "$project"
  "$phpbin" vendor/drush/drush/drush.php site:install minimal --yes \
    --db-url="sqlite://localhost/$state_dir/site.sqlite" \
    --site-name='Actools disposable test site' \
    --account-name='actools_test_admin' --account-pass="$test_password"
  [[ "$("$phpbin" vendor/drush/drush/drush.php status --field=bootstrap)" == Successful ]] || {
    printf '%s\n' 'The disposable Drupal base site did not bootstrap successfully.' >&2
    exit 1
  }
)
unset test_password
printf 'Disposable Drupal project: %s\nBase-site state: %s\n' "$project" "$state_dir"
