# Contributing

Develop changes in a branch and submit a pull request. Install this repository as `web/modules/custom/actools_lesson` in an isolated Drupal development project, with matching core development dependencies. Keep generated files, credentials and site data outside this repository.

Run the relevant native Drupal tests described in [tests/README.md](tests/README.md), PHP lint and JavaScript syntax checks. For reader or authoring changes, exercise the installed Drupal pages with keyboard input, narrow screens, no JavaScript and print. Record actual environments and remaining deployment limits in [VALIDATION.md](VALIDATION.md).

Preserve stable lesson/component IDs, native revisions, node and field access, cache metadata, filtered text and private progress. A schema change needs an update path that preserves existing sites. Changes to teaching content invalidate the relevant read/review version deliberately.

The site owns its header and branding; follow the [theme integration contract](docs/THEME_INTEGRATION.md). Keep existing Topic/Resource fields, Layout Builder configuration and legacy resources intact unless a separately reviewed migration explicitly changes them.

Regenerate `SOURCE_MANIFEST.json` SHA-256 entries after changing packaged files; exclude the manifest itself. Preserve third-party licenses and pinned MathJax/font provenance.
