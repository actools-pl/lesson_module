# Operations and updates — Actools Lessons 1.0.3

The Drupal machine name is `actools_lesson`; `lesson_module` is the GitHub repository name. Deploy the repository root as `modules/custom/actools_lesson` inside the actual Drupal document root. See [INSTALLATION.md](INSTALLATION.md) for explicit Drush root/site examples and role setup.

## Update an installed 1.0.0, 1.0.1 or 1.0.2 site

Version 1.0.3 requires a code replacement and cache rebuild. It introduces no module database schema migration. Preserve the site's database, configuration, uploaded/public files, native revisions and user data. **Do not uninstall/reinstall the module, remove its fields, or reimport the demonstration to perform this update.**

1. Record the current release/commit and local overrides. Back up the database, active/exported configuration, uploaded files and current code using the site's normal recovery procedure. Verify that the backup can be restored. Aegir site Backup excludes shared platform code: retain both the site archive and a separate off-server platform/custom-code backup with its Composer manifest, lockfile and reviewed module commit.
2. On BOA, follow the [Actools installation procedure](https://github.com/actools-pl/actools_bs5/blob/main/INSTALL.md) and [BOA platform guide](https://github.com/actools-pl/boa/blob/hetzner-drupal11/hetzner/ACTOOLS_BS5.md). Build a new unregistered platform as `o1.ftp`, finish dependency/code work before registration, register the actual `web` document root and wait for Verify. Rehearse migration on a clone. Preserve the reviewed `composer.lock`; do not run Composer as root, update shared dependencies blindly, or pull `main` inside a live platform. Retain Claro for administration. Other managed platforms should use their equivalent supported release process.
3. Test the reviewed 1.0.3 source on a staging copy with the actual site PHP, database, theme, access modules and moderation configuration.
4. Deploy the complete module directory through the established release process. Retain normal filesystem ownership; the PHP service should not need write access to module code.
5. Rebuild caches in the intended site's context. In a Composer project with Drush already installed, for example:

   ```sh
   /absolute/path/to/project/vendor/bin/drush --root=/absolute/path/to/project/web --uri=https://site.example.test status
   /absolute/path/to/project/vendor/bin/drush --root=/absolute/path/to/project/web --uri=https://site.example.test cr
   ```

   Verify `status` before the cache rebuild. Use the platform's approved Drush alias/executable instead where required. A shell inside the Drupal document root does not imply that `vendor/bin/drush` lives there.
6. Reload open lesson editor forms. Check public content and the latest private drafts before allowing normal editorial work to resume.

The cache rebuild is needed for changed services, routes, templates and versioned 1.0.3 browser assets, including the unaggregated mathematics integration. Do not edit generated aggregate files. Follow any additional update/configuration steps required by the site's deployment pipeline; this release itself has no `hook_update_N()` migration to execute.

## Staging acceptance on the actual host

Final **1.0.3** native validation passed **86 tests / 1272 assertions** on **Drupal 11.4.10, PHP 8.3.6 and SQLite**, followed by the retained browser regressions. Unit 8/53, Kernel 48/718 and Functional 30/501 ran through the shared disposable test entry point. The full installed-Drupal UI baseline remains 1.0.1; the focused theme and failure fixtures have their own scope. PHP 8.4, Percona, BOA/Nginx, the installed production theme and CSP remain target-platform acceptance checks. See [VALIDATION.md](../VALIDATION.md) for exact evidence and hosted-CI status.

Use a disposable lesson and chapter for the following checks:

- Confirm extension discovery, dependency enablement and the workbench. Test an ordinary author and publisher, not only the site owner. Confirm learners cannot see a private pending draft or its metadata.
- Save a draft using CKEditor and structured controls; add, duplicate, remove and reorder content; reopen it; inspect figures and typeset equations. Check a 390-pixel editor and a narrow reader for local scrolling and usable focus.
- Publish reviewed content, then change it and save a private draft. In a separate anonymous session, confirm the prior public text remains available. Review and publish the replacement, then confirm the public version changes.
- Check chapter membership, Core/Extension sequencing, optional steps and native historical revision access. If Content Moderation is configured, verify its transitions and permissions remain authoritative.
- Warm caches as anonymous and as two different learner accounts. Check separate personal read marks/resume, denied node or field access, and ordinary public rendering. Keep user-specific progress responses private through any reverse proxy/CDN.
- Check the real global header/admin toolbar offsets, explicit light/dark theme choices, print header and full-resolution diagram links. The module supplies its reader, while the site theme retains its global header and branding.
- Verify that local JavaScript, styles, WOFF2 fonts and mathematics assets are served successfully. Validate the site's CSP against the local MathJax accessibility worker, which uses a `blob:` worker in the exercised configuration. Adjust policy through the site's normal security review rather than disabling it broadly.

See [VALIDATION.md](../VALIDATION.md), [THEME_INTEGRATION.md](THEME_INTEGRATION.md) and [tests/README.md](../tests/README.md). The theme guide describes the 1.0.2 scoped compatibility bridge for current `actools_bs5` behavior and how it relates to the theme’s open pull requests. Run automated tests only in a disposable development installation, with separate test databases and files.

## Content, taxonomy and configuration ownership

Existing compatible `actools_topic` and `actools_resource` bundles are reused. The installer adds missing fields and refuses conflicting storage; it does not reapply the theme recipe or convert legacy `kind=lesson` resources. Existing Topic Layout Builder overrides and old `field_actools_resources` cards are not migrated into the chapter's ordered lesson references. Review any existing display override that intercepts the managed reader.

Do not delete conflicting field storage to make installation pass. Compare field types, cardinality, reference targets and the Resource-kind allowed values in staging, then make a deliberate, data-preserving configuration change. Export reviewed configuration through the site's normal workflow. Existing text-format/editor configuration is preserved rather than silently replaced.

Optional `edu_topics` reference fields are created when that vocabulary is present at installation. No taxonomy seed, term code field or external concept identifier is guessed or rewritten. If the vocabulary is added later, a developer can invoke the documented idempotent structure installer in a reviewed development update, then export the resulting configuration for normal deployment. See [CONTENT_AND_EXTENSION.md](CONTENT_AND_EXTENSION.md).

## Files, progress and recovery

Use public teaching images only. The figure widget stores managed files publicly; node publication is not a download-access barrier for their URLs. Upload a new file for changed artwork. File usage from historical revisions is retained, so a cleanup job must not assume that a diagram absent from the current revision is unused. Confidential diagrams need a separately reviewed private-file authoring/download extension.

Reading progress belongs to each Drupal user and is held in Drupal user data. It tracks stable step identities and teaching-content versions; it is not a grade or assessment-attempt store. Preserve user data when restoring the database, and include progress in applicable site account-retention/privacy processes. Normal GET requests do not write read marks. Deleting a lesson removes its progress; uninstall removes the module's progress records.

A pending replacement draft is a native non-default revision. Do not republish, delete or rewrite revisions as a routine cache repair. Native revision restore preserves history but can require text-format permissions and advances restored teaching-content versions. When retiring a chapter, separately review its lessons' publication/access: unpublishing the chapter does not automatically revoke independent lesson access, and public file URLs remain public.

If a deployment fails, use the platform's release rollback and restore procedure. Recover a consistent code/database/files set when required, and account for any edits made after the backup before restoring older data. Keep a failing editor tab's unsaved text before reloading; stale-form rejection is deliberate protection against overwriting newer work.

Uninstall is an intentional destructive removal process, not an update or troubleshooting step. Content and custom field-storage guards can block it. Export/retain the lesson documents, files and required progress first; then follow a reviewed field removal/purge and module removal plan. Uninstalling only the optional example submodule leaves its editable content in place; it is not a content cleanup action.
