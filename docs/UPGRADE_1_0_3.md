# Corrective upgrade to Actools Lessons 1.0.3

This release responds to the independent review of commit `3e3baae308e1fe42cf3d4f37479fbabffb200c4d` (1.0.2). It corrects the existing lesson experience and adds regression protection. It retains the native revision-owned field, eight component types, chapter/step navigation and private reading progress.

## Corrections

| Review | Correction |
| --- | --- |
| R01 | Whole-form capacity checks, a signed submission-completeness manifest tied to widget inventories, and rejection of missing controls or an oversized body before authored values are captured or saved. Native growth is bounded to 120 components across a lesson. |
| R02 | An empty steps field stays empty on an unmanaged Resource. Notes and Assignment forms save without an unwanted required step; the dedicated Create lesson path retains its initial step. |
| R03 | Restored/reintroduced step UUIDs receive a content version above their retained native history. Equivalent current content keeps its existing version. Old read marks no longer match different restored text in the reviewed removal/restore sequence. |
| R04 | Publication and workbench readiness use the same content-completeness check. Empty prose, empty editor markup and missing example/practice prompts cannot count as ready. Hints and answers remain optional. Equation and figure structural requirements are retained. |
| R05 | Printed text uses a complete light palette under OS dark preference and both explicit dark-attribute conventions. Screen appearance remains supported. |
| R06 | Reader, progress and mathematics behaviors include a matching root context as well as descendants, with exactly-once initialization for delayed insertion. |
| R07 | Installation checks both `core` and `extension` choices before changing configuration. Drupal allowed-values callbacks and grouped choices are resolved through the native API; compatible custom choices remain intact. |
| R08 | Reader construction retains generated URLs, including absolute URLs and their cache metadata. The unused reparsing path is removed. |
| R09 | Missing/blocked math entry scripts and rejected startup/typesetting display one explanatory notice while preserving editable TeX source. |
| R10 | Full-resolution figure links take their individual accessible names from the meaningful image alternative text. |

## Authoring capacity

For the full supported native-editor capacity, configure and verify the effective **web PHP** settings, not only the CLI runtime:

```ini
max_input_vars = 4096
post_max_size = 128M
upload_max_filesize = 20M
```

The editor permits new growth up to 120 teaching components across all steps, while retaining the existing 40-step and 60-components-per-step bounds. It measures the processed complete form, including ordinary node metadata, text-format controls and reserved AJAX inputs. A smaller server limit can support a smaller form; an unsafe whole form is blocked with an explanation. Collapsing editing sections does not reduce submitted controls.

`max_file_uploads` must also cover the actual pending upload controls. Already-stored single-file figures do not consume a new upload slot. To permit 120 simultaneous pending figure uploads, the count limit would need to be at least 120; their **combined** body must still fit `post_max_size`. The 20M per-file limit does not imply that 120 new 20M files fit into a 128M request. Upload figures in manageable requests using their normal controls.

Use the form's server-capacity details to check the actual site. Apply settings through the BOA/platform-supported PHP configuration process and reload PHP where required. This module reports limits; it does not change server configuration. Keep production error display disabled through the site's normal operations procedure.

Existing larger stored documents are preserved. The editor blocks further growth, and complete edits/removals require enough server capacity to receive that entire existing form. Do not delete field data or reinstall the module to reduce it. Reorganize an oversized document through a deliberate, backed-up editorial/development operation.

The completeness manifest contains control names and identity digests, not a hidden copy of authored teaching content. It accepts legitimate absent unchecked/disabled controls and rejects missing required scalar controls. AJAX builder and managed-file replacements also update the outside manifest and capacity status. Identity signing is independent of editable numeric step/component order. A truncated, stale-manifest or over-body-limit request is rejected without creating a saved revision; copy unsaved text from the original tab before reloading.

## Upgrade procedure

1. Follow [INSTALLATION.md](INSTALLATION.md) and [OPERATIONS.md](OPERATIONS.md): back up site data and shared platform code, build a reviewed staging/new BOA platform, and record the exact module commit.
2. Check effective PHP limits using a representative lesson. Existing conflicting learning-path storage must be corrected deliberately; this update does not rewrite site choice lists or reapply the theme recipe.
3. Deploy the complete `actools_lesson` directory and rebuild Drupal caches in the intended site's Drush context. No module database schema migration is introduced. Do not uninstall/reinstall or reimport the demonstration.
4. Reload open editor forms. Old forms predate the submission manifest and must not be resubmitted after deployment. Keep a copy of unsaved work first.
5. Run the acceptance checks below before broad authoring resumes.

Existing native revisions, stable UUIDs, managed files and account progress remain in place. Publication now refuses teaching components that previously could be structurally valid but empty. Complete those drafts before publishing.

Step-version allocation uses retained native revision history. Deliberately purging revisions removes evidence of those old versions; this release adds no durable version registry. Preserve teaching history needed for restoration and avoid purging history and manually reusing removed step identities as a routine cleanup.

## Acceptance and repeatable checks

Run the shipped [test entry point](../tests/README.md) only in a disposable Drupal development installation. It bootstraps a matching core/core-dev project, runs native Unit/Kernel/Functional tests and the retained browser regressions. Each Functional class receives a fresh local server and database. GitHub Actions defines PHP 8.3 and 8.4 jobs; executed results and deployment limits belong in [VALIDATION.md](../VALIDATION.md).

On the actual staging host, use representative Surface Tension material:

- Edit the first, middle and last components of a substantial lesson, save/reopen, and inspect every changed value. Repeat after an AJAX add/duplicate action. Test a rejected incomplete request against a disposable lesson and confirm the previous revision remains intact.
- Save ordinary Notes and Assignment resources with no teaching steps. Check the dedicated lesson form and existing saved step data.
- Check incomplete prose/question drafts, readiness counts and reviewed publication. Verify that public content remains available while a replacement draft is private.
- Repeat the removed-step restore sequence and verify the learner is asked to revisit changed text.
- Check delayed progress under the site's actual BigPipe/AJAX configuration, print under explicit dark appearance, and a deliberately blocked math entry script. Retain one mathematics renderer owner.
- Check the installed `actools_bs5` header/admin toolbar, real PHP 8.4/Percona/BOA behavior, local fonts/scripts and CSP. The local test environment and focused fixtures do not certify that complete production deployment.
