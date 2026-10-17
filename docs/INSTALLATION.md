# Installation — Actools Lessons 1.0.2

This repository contains the Drupal module at its root. The GitHub repository name is **not** its Drupal machine name.

| Item | Name |
| --- | --- |
| GitHub repository | `actools-pl/lesson_module` |
| Drupal module directory and machine name | `actools_lesson` |
| Name in Drupal Extend | Actools Lessons |
| Optional example submodule | `actools_lesson_demo` |
| Composer metadata name | `aarin/actools_lesson` |

Clone or extract the repository into a directory named `actools_lesson`. The result must contain `actools_lesson/actools_lesson.info.yml`, not an extra nested repository directory. The Composer metadata does not by itself make this repository an available Packagist package.

## Requirements and deployment target

The release declares Drupal **11.3+ within Drupal 11** and PHP **8.3+**. Its declared core dependencies include Node, Field, Text, Filter, CKEditor 5, Options, File, Image, User and Taxonomy. Drupal enables required dependencies when enabling the module. Teaching figures require writable public file storage. MathJax and fonts are bundled locally; no frontend package build or remote CDN is required.

Final **1.0.2** automated validation passed **60 tests / 767 assertions** on **Drupal 11.4.10, PHP 8.3.6 and SQLite**. The full Chromium authoring/reading workflows were exercised on 1.0.1; the status of the new focused 1.0.2 theme-adapter fixture is recorded separately in the validation record. These checks do not certify a complete production theme/site. PHP 8.4, Percona, BOA/Nginx, the installed production theme and its content-security policy remain staging checks. See [VALIDATION.md](../VALIDATION.md) for the evidence and limits.

## Choose the correct codebase and site

On a BOA or other managed platform, identify the platform's authoritative codebase and its supported deployment procedure first. Install on a staging copy, with restorable database, configuration, uploaded-file and code backups. A codebase may serve several sites: verify each affected site before enabling or updating an extension.

For the existing Actools BOA setup, read the [theme installation guide](https://github.com/actools-pl/actools_bs5/blob/main/INSTALL.md) and [BOA optional-theme platform guide](https://github.com/actools-pl/boa/blob/hetzner-drupal11/hetzner/ACTOOLS_BS5.md) alongside this module guide. Their platform procedure applies even though installing this module does not require installing the theme:

- Build a new, unregistered platform in the real `o1.ftp` limited shell. Finish Composer work and include the module's reviewed source before Aegir registration. Do not run Composer as root or pull `main` into a platform already serving sites.
- Preserve the reviewed platform `composer.json` and `composer.lock`. Reproduce an approved dependency build with `composer install`, using the platform's procedure. A manually cloned module is not pinned by the platform lockfile; record its reviewed commit separately.
- Register the actual Drupal document root, normally the new platform's `web` directory, as Aegir's Publish Path. Wait for platform Verify to pass, then rehearse site migration on a clone before production migration.
- Retain Claro as the administration theme. Verify the module's editor there and its reader under the site's frontend theme.

Aegir's site Backup excludes shared platform code. Keep the site archive and a separate off-server copy of platform/custom code, manifest, lockfile and module commit information.

Use the platform's approved Drush executable, PHP runtime and filesystem owner. Do not run an unsolicited `composer require`, update the platform's shared dependencies, or overwrite a managed release in place. The module has no additional Composer runtime package requirement; incorporate its source through the existing managed-code deployment process.

For a conventional Composer project, the project directory contains `composer.json` and `vendor/`; its Drupal document root is commonly `web/`. They are different directories. For example, when the destination does not already exist:

```sh
cd /absolute/path/to/drupal-project
git clone https://github.com/actools-pl/lesson_module.git web/modules/custom/actools_lesson
```

This initial repository upload has no release tag. Select the reviewed 1.0.2 source commit through your normal release process and record its commit ID. Do not assume that an arbitrary future repository HEAD is this release. For an existing checkout, inspect local changes before updating it; do not clone over it.

A non-Composer or managed platform may use a different document root. Place `actools_lesson` under that actual root's `modules/custom/`, using the platform's staging/build process.

## Enable with an explicit Drupal context

In a Composer project with Drush already installed, this example uses the project-local executable and an explicit document root and site URI:

```sh
cd /absolute/path/to/drupal-project
vendor/bin/drush --root=/absolute/path/to/drupal-project/web --uri=https://staging.example.test status
vendor/bin/drush --root=/absolute/path/to/drupal-project/web --uri=https://staging.example.test en actools_lesson -y
vendor/bin/drush --root=/absolute/path/to/drupal-project/web --uri=https://staging.example.test cr
```

Replace the example paths and URI. Confirm that `status` identifies the intended Drupal root, site and connected database before enabling. If your shell is already inside the Drupal document root, `vendor/bin/drush` usually is **not** there: invoke the approved executable by its project-relative or absolute path, retaining `--root` and `--uri`. A configured platform Drush alias is also appropriate when it resolves to the intended site. Enabling through **Extend** is an alternative.

Open **Content → Actools Lessons**, `/admin/content/actools-lessons`. The module creates missing compatible Topic/Resource structure. If installation reports conflicting field storage, review the site's configuration before retrying; do not delete existing content or fields to force installation.

## Permissions and existing workflows

The module grants no role permissions. Configure roles at `/admin/people/permissions`, then verify with actual non-administrator accounts.

| Role responsibility | Access to arrange |
| --- | --- |
| Author workbench | `access actools lesson authoring` plus the appropriate native create/edit permissions for Resource (`actools_resource`) and Topic (`actools_topic`). |
| Rich text | `use text format actools_lesson_text`, or access to the deliberately selected existing format. |
| Publisher | Native access to edit Published status, as well as access to edit the content. On tested Drupal 11.4.10, core allows this through `administer node published status` or the broader `administer nodes`; site access modules may add restrictions. |
| Revision reviewer | Native view/revert revision permissions for the relevant bundle, plus the content access required by Drupal. Grant revision deletion separately only if intended. |
| Learner | `access content` and the site's applicable node/field access. Signed-in learners can maintain their own reading progress. |

Where core Content Moderation governs Resource, its configured transitions remain authoritative; the module defers its custom lesson draft/publish actions. Check the installed workflow and transition permissions before following the default authoring flow.

## Optional example and taxonomy

Enable `actools_lesson_demo` only when you intend to create its editable, published Surface Tension chapter, three Core lessons, one Extension lesson and original diagrams. It is a small original example, not an import of `ST.zip`. It creates no assessment attempts and changes no role grants or default theme. Uninstalling the optional example submodule leaves its content in place; do not re-enable it as an update strategy.

If `edu_topics` exists when the main module is enabled, optional chapter/concept term-reference fields are added. Choose existing terms explicitly. The installer does not create the vocabulary, import the external taxonomy seed, guess a code field or remap legacy resources. For the compatibility contract, see [ARCHITECTURE.md](ARCHITECTURE.md) and [CONTENT_AND_EXTENSION.md](CONTENT_AND_EXTENSION.md).

For the 1.0.2 compatibility bridge to the current `actools_bs5` header and submit-button behavior, and its relationship to the theme’s open pull requests, see [THEME_INTEGRATION.md](THEME_INTEGRATION.md). Continue with [EDITOR_GUIDE.md](EDITOR_GUIDE.md) and the staging/update checks in [OPERATIONS.md](OPERATIONS.md).
