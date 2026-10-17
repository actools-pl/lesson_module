# Repeatable Drupal and browser checks

`tests/run-ci.sh` is the common local and CI entry point. It runs the native Unit and Kernel suites, discovers every `*Test.php` under `tests/src/Functional`, then runs the retained Chromium regressions. Each Functional file gets a fresh PHP server and SQLite database. A failing suite stops the run and retains its reports.

The GitHub workflow in `.github/workflows/tests.yml` uses the same scripts on Ubuntu 24.04 with PHP 8.3 and 8.4. It pins Drupal and matching `drupal/core-dev` to 11.4.10, Drush to 13.8.0 and Playwright to 1.62.1. Top-level Drupal versions are pinned; Composer resolves their dependencies into a lock file retained with each run. This tests one Drupal patch version, not every version permitted by the module's Composer constraint.

The workflow passed both PHP jobs for 1.0.3. At 1.1.0 release preparation, its new commit had not yet been published or run; local results do not establish a hosted PHP 8.4 pass for the new integration. Check the Actions result for the exact published commit. The workflow runs on pushes, pull requests and manual dispatch, has repository `contents: read` permission, does not retain checkout credentials, and contains no deployment or repository-write step.

## Create a disposable development project

Use Linux with Bash, Composer 2, Node 22+ and PHP 8.3+. The workflow selects Node 24. PHP needs Drupal's usual extensions plus SQLite, DOM, GD, mbstring, cURL and ZIP. Use matching PHP runtimes for Composer, PHPUnit and the test server.

From the module checkout:

```sh
export ACTOOLS_TEST_PROJECT="$(mktemp -d /tmp/actools-drupal.XXXXXX)"
bash tests/bootstrap.sh
npm --prefix tests/browser ci --ignore-scripts --no-audit --no-fund
(cd tests/browser && npx playwright install --with-deps chromium)
bash tests/run-ci.sh
```

The browser dependency command installs Chromium and its system dependencies on the disposable machine. The module itself has no Node runtime dependency.

Bootstrap accepts only a new or empty project directory. It installs a minimal base site with a separate temporary SQLite database and links this module into `web/modules/custom/actools_lesson`. It never reuses an existing nonempty Drupal project. Functional tests create their own test sites; demo content is unnecessary. Keep the disposable project, generated base-site state and test results separate from a production installation.

The default Drupal pin can be changed to another explicit 11.4.x patch with `ACTOOLS_TEST_DRUPAL_VERSION`; bootstrap pins `core-recommended`, scaffold, project-message and `core-dev` together. Re-run all checks after changing it.

## Existing disposable project and individual suites

An existing test project must contain the matching core-dev dependencies, a working disposable base site and this checkout at `web/modules/custom/actools_lesson`. The shared runner verifies that module path before execution. The project's settings remain unchanged during test runs.

```sh
export ACTOOLS_TEST_PROJECT='/absolute/path/to/disposable-drupal-project'
bash tests/run-ci.sh unit
bash tests/run-ci.sh kernel
bash tests/run-ci.sh functional
bash tests/run-ci.sh browser
```

Unit and Kernel tests do not launch a web server. Functional tests use the real Drupal document root as both server working directory and document root, with an absolute core router path. This is significant for namespace discovery and repeated requests. Run the script and its server in the same environment/network namespace.

Both PHP CLI tests and the Functional server receive `memory_limit=768M`, `max_input_vars=4096`, `post_max_size=128M` and `upload_max_filesize=20M`. They also use `display_errors=0` and `log_errors=1`: the deliberate request-truncation case must log PHP's parser warning while allowing Drupal's intended error response, without warning text causing a secondary header/session failure. Each runner writes these settings into its results directory and appends that directory to `PHP_INI_SCAN_DIR`, preserving existing/default scan directories and extensions. This also configures PHPUnit's isolated child processes, which do not inherit the parent command's `-d` arguments. The effective startup settings and loaded/scanned INI paths are retained in `php-settings.json`. These are the supported full-editor test settings. The retained capacity regressions also check request completeness and safe failure conditions; a collapsed disclosure does not reduce submitted inputs. Do not interpret the fixture settings as validation of a differently configured production server.

For only the Functional stage, the lower-level entry point remains available:

```sh
bash tests/run-functional.sh
```

It discovers new Functional files automatically, runs them serially and stops only its own server process. There are no automatic retries that could hide an intermittent failure.

## Browser fixtures

`tests/browser/run.mjs` starts a loopback-only static fixture server on an available port and runs `regressions.mjs`. It serves actual module assets and the installed Drupal `drupal.js`/`once` implementations. Browser fixtures do not access the base Drupal site or write its database.

Retained cases cover explicit/OS appearance in print, matching behavior root contexts and exactly-once enhancement, successful local MathJax rendering, rejected and missing/blocked entry paths with preserved TeX/fallback notices, distinct figure link names, the scoped theme-header adapter and optional shared Layout Builder containment. The shared-layout fixture starts with actual anonymous canonical HTML captured from the native Functional installation, then applies explicit narrow-region and print stress variants using actual core layout CSS. These are focused failure regressions. Full installed-theme, BigPipe, production CSP and target-stack acceptance remain separate checks.

The package lock makes browser dependencies repeatable. A direct browser-only invocation is also supported:

```sh
export DRUPAL_ROOT="$ACTOOLS_TEST_PROJECT/web"
node tests/browser/run.mjs
```

## Configuration and retained evidence

| Variable | Purpose |
| --- | --- |
| `ACTOOLS_TEST_PROJECT` | Required disposable Drupal Composer project. |
| `ACTOOLS_TEST_WEBROOT` | Optional webroot; defaults to the project's `web`. |
| `ACTOOLS_TEST_PHP` | Optional PHP executable; defaults to `php`. |
| `ACTOOLS_TEST_COMPOSER` | Bootstrap Composer executable; defaults to `composer`. |
| `ACTOOLS_TEST_NODE` | Optional Node executable for the common runner. |
| `ACTOOLS_TEST_PORT` | Functional server port; defaults to 8096. |
| `ACTOOLS_TEST_RESULTS` | Optional empty results directory; otherwise a new directory under `/tmp`. |
| `ACTOOLS_TEST_BROWSER_OUTPUT` | Results directory for direct browser invocation. |
| `ACTOOLS_TEST_CHROMIUM` | Optional local Chromium executable; normally Playwright selects its pinned browser. |
| `ACTOOLS_TEST_CHROMIUM_ARGS` | Optional JSON string array of launch arguments for a special local runtime. |
| `ACTOOLS_TEST_PLAYWRIGHT_MODULE` | Optional absolute module import for an already provisioned local Playwright installation. Normal npm/CI runs leave this unset. |

The scripts print the retained results path. Native stages write JUnit and logs; the common runner also retains the PHP version and resolved Drupal Composer lock. Browser execution writes `summary.json` with pass/fail status, the actual browser version, checks or the failure stack. The hosted workflow uploads reports for seven days, including on failure; it excludes test databases from the artifact patterns.

Review the first failure and its request/site evidence before rerunning. Earlier development encountered isolated-site dispatch failures in a long-lived combined server; the fresh-server-per-file approach retains that boundary explicitly. Report a failed attempt honestly even when a later run passes. See [VALIDATION.md](../VALIDATION.md) for release-specific results and limits.

The native suites use Drupal storage, access handlers, node revisions, filtered text, managed files, real native forms and account-scoped progress. Layout Builder integration tests enable the optional add-on only inside their isolated fixtures, exercise native default-layout editing/configuration schema, and verify saved layouts, unmanaged content, protected overrides, private revisions and safe fallback. The base suite continues to test the main module without requiring the add-on. The `actools_lesson_test` module is test support only and belongs only in disposable test sites.
