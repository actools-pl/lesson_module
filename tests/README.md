# Drupal integration tests

Kernel and functional tests exercise Drupal storage, access handlers, render routes, configuration and native revisions. Unit tests supplement progress-store boundary cases.

Run them in an isolated Drupal development checkout containing this module, with the matching `drupal/core-dev` dependencies. Keep the test database and files separate from production.

```sh
export SIMPLETEST_DB='sqlite://localhost//absolute/path/to/test.sqlite'
export SIMPLETEST_BASE_URL='http://127.0.0.1:8080'
export BROWSERTEST_OUTPUT_DIRECTORY='/absolute/path/to/browser-output'
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/actools_lesson/tests/src/Unit
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/actools_lesson/tests/src/Kernel
```

Create the browser-output directory before using the environment variables above. Unit and Kernel tests do not need a web server. Use the matching Drupal development dependencies and a working disposable local Drupal installation for Functional tests; the base site need not contain demo content. Some filesystem and database drivers require additional setup described in Drupal core's test documentation.

## Functional tests with isolated server lifetimes

The supplied runner gives each Functional class a fresh local PHP server and a distinct SQLite database, retaining JUnit, browser output and logs in a new temporary directory. It does not use the exported `SIMPLETEST_DB` above. SQLite support must be enabled in the chosen PHP runtime. From the module checkout:

```sh
export ACTOOLS_TEST_PROJECT='/absolute/path/to/disposable-drupal-project'
bash tests/run-functional.sh
```

Optional settings are `ACTOOLS_TEST_PHP` (PHP executable), `ACTOOLS_TEST_PORT` (default 8096) and `ACTOOLS_TEST_WEBROOT` (default the project's `web` directory). The module must be installed at that webroot's `modules/custom/actools_lesson`. Run the script in the same environment/network namespace as the local server. It exits on a failing class and stops only the server it launched; inspect retained results before retrying.

The runner uses the core router from the actual webroot working directory and creates its output directory before PHPUnit starts. This matches the final class-isolated verification approach recorded in [VALIDATION.md](../VALIDATION.md). Do not point it at a production codebase or database. It does not certify the production database, theme or BOA stack.

The suite covers compatible existing field configuration, idempotent installation, unmanaged resource separation, rejected conflicting storage, outline ownership and ordering, the database uniqueness guard after stale validation, field and node access cache metadata, revision isolation, and parity of guided, Read all and print routes. It also repeats canonical route requests across step and view changes to exercise warm caches. The progress and structured authoring test classes cover their respective domains.

`LessonEditorialWorkflowTest` submits the actual managed lesson node form: initial draft, published content retained while a pending draft changes, reopening the latest draft, reviewed publication, stale browser forms, forged revision snapshots, native publish permission and private preview access. `EditorialSnapshotTest` also detects same-revision API changes and cross-account token reuse. `EditorialModerationCompatibilityTest` configures a real core Content Moderation workflow and verifies the module defers to it.

The `actools_lesson_test` module is test support only. Do not enable it on a normal site.
