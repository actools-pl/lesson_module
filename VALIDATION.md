# Validation — Actools Lessons 1.1.0

The frozen 1.1.0 source passed the shipped `tests/run-ci.sh all` entry point on 17 October 2026, in a newly bootstrapped disposable Drupal project. This includes the new optional shared Layout Builder integration and all prior regressions. The full installed-Drupal Chromium journey remains the earlier 1.0.1 baseline; focused theme, failure and new Layout Builder fixtures are scoped separately below.

## Final automated results

| Suite | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| Unit | 8 | 53 | Passed |
| Drupal Kernel | 59 | 882 | Passed |
| Drupal Functional, native forms and real AJAX requests | 38 | 678 | Passed |
| **Total** | **105** | **1613** | **Passed** |

All native tests passed with zero failures, errors or skipped cases. JUnit aggregate counts matched the individual test cases. The final frozen common runner completed Unit, Kernel, every discovered Functional file and the retained browser suite without retries. Each Functional file used a fresh HTTP server and separate SQLite database; the disposable base site's settings stayed fixed.

The tests used `max_input_vars=4096`, `post_max_size=128M`, `upload_max_filesize=20M` and PHP's default 20-upload count. Parser warnings were logged with error display disabled so the deliberate input-truncation test could reach Drupal's intended rejection response. A representative 120-practice-component native form used 1,486 input slots including the 128-input reserve. This does not certify full-editor Chromium performance or an arbitrary production form with additional fields.

Focused Layout Builder development caught and corrected an access-service interface declaration, a late-render wrapper and the core hidden-label value type. Failed fixture attempts also used a pruned login menu item, serialized an unavailable layout before importing it, assumed a theme-specific error class and compared unnormalized in-memory/SQL values; corrected fixtures then passed before the final frozen run. These attempts are not counted as final passes. Existing 1.0.3 request-capacity and validation-recovery regressions were rerun in full.

First-party PHP lint, JavaScript/MJS syntax, shell syntax, workflow actionlint, Composer metadata validation, Twig syntax and local Markdown file targets also passed. The GitHub Actions workflow defines PHP 8.3/8.4 jobs. The prior 1.0.3 commit passed both hosted jobs; hosted execution for this new 1.1.0 commit had not occurred when this record was prepared. Check Actions for the exact published commit before treating this release as exercised on PHP 8.4.

## Verified environment

| Component | Actual version |
| --- | --- |
| Drupal | 11.4.10 |
| PHP | 8.3.6 |
| Database | SQLite |
| PHPUnit | 11.5.57 |
| Drush | 13.8.0 |
| Browser used for baseline UI and new regressions | Chromium 153.0.8010.0 through Playwright |
| Local mathematical renderer and font package | MathJax 4.1.3 / New Computer Modern 4.1.3 |

For the 1.0.1 baseline, the main module and optional demo were enabled on a fresh minimal-profile installation. The supplied CKEditor 5 configuration validated with zero typed-config violations. Cold and warm checks of eight demo/canonical/reader routes produced 16 HTTP 200 responses. A read-only database integrity check after the full author workflow returned `ok`.

## What the automated tests exercise

- Clean and idempotent structure setup, preservation of compatible existing fields/displays, and rejection of conflicting field types, reference targets and taxonomy storage.
- Managed content versus legacy resources; one canonical chapter owner; ordered paths; duplicate and stale ownership rejection; the database uniqueness guard; non-default chapter revisions leaving the public route unchanged.
- Node and displayed-field access, restricted titles, denied navigation cache metadata, access revoke/reallow after warm rendering and separation of Core/Extension navigation.
- Guided, Read all and print parity; safe filtered text; introduction rendering; invalid query shapes; obsolete stable step links; arbitrary revision query isolation; exact native historical revision viewing with proper access.
- Canonical warm-cache mode/step contexts and native render metadata, including Drupal's fixed render-cache identity.
- Structured native authoring actions and persisted UUIDs; duplicate/move/remove/save/reopen behavior; signed identity controls; formatted-text permissions; image validity, temporary upload ownership and historical file usage.
- Teaching-content version checks, title-only edits, exact historical restore through Drupal's native `createRevision()` API, and rejection of modified historical objects that attempt to bypass the ordinary version guard.
- Durable account progress; explicit completion; required versus optional steps; undo/bulk/reset; stale rendered-version rejection; valid POST and invalid CSRF; GET without writes; warmed anonymous/Alice/Bob separation; final-step redirects and private chapter resume.
- Private draft saves preserving an existing published revision, reviewed publication, authorized latest-draft reopening and preview, revision-specific access denial, stale/forged save snapshots and compatibility with an existing core Content Moderation workflow.
- One accessible progress summary and native meter, accurate optional-only counts, one guided primary action, explicit Read-all step selection and safe previous-lesson navigation from continuous reading.
- Optional theme-adapter assets gated to `actools_bs5` and its subthemes, excluded from unrelated active themes including Claro, with theme cache variation and existing cache metadata preserved.

- Whole-form input/upload capacity, native 120-component first/middle/last saves, real PHP input-parser truncation, discarded oversized POST, omitted scalar controls, stale AJAX inventories, managed-file removal, stored-file upload counting and direct numeric step/component ordering.
- Empty unmanaged Notes/Assignment saves, preservation of existing saved steps, removed/reintroduced step-version high-water marks, shared publication/readiness completeness, callback/group-aware path preflight, absolute generated URLs and individual figure-link names.

- Optional Layout Builder installation without display/override rewrites; exact contextual reader rendering, stored shared-default reloads and full-to-default display fallback; preservation of ordinary unmanaged fields and overrides.
- Native Manage layout editing and display opt-in/opt-out; recoverable invalid-save/core-disable errors; missing, duplicate, wrong-context and malformed imported layouts falling back to the complete original reader.
- Managed per-content override denial, including an older published unmanaged node with a newer managed draft; shared public versus private draft/historical revision separation; warm field access, query and account progress cache boundaries.

See [tests/README.md](tests/README.md) for the shared entry point, disposable bootstrap and exact test source.

## Retained browser regressions — 1.0.3, rerun for 1.1.0

The shipped common entry point also ran the retained Playwright suite with actual Drupal `drupal.js`/`once`, module assets and the bundled local MathJax renderer. It passed with zero browser page errors:

- Matching root reader/progress/mathematics contexts, late insertion, replacement progress forms and exactly-once initialization/print handling.
- All six OS/explicit appearance combinations in print, with complete light ink/background palettes and retained screen appearance.
- Actual local mathematics success, rejected typesetting/startup, missing or blocked entry scripts before window load, and late attachment after load. One fallback notice appears and raw TeX remains available.
- Distinct figure-link names and the theme adapter's handoff to native header signals.

These are retained focused fixtures. The complete installed-Drupal UI journey below remains explicitly the earlier 1.0.1 baseline. The full production theme, real BigPipe runtime, CSP and BOA stack require staging acceptance.

## Layout Builder browser regressions — 1.1.0

The retained browser suite uses an anonymous canonical shared-layout root captured from the real Drupal Functional fixture. Native Functional tests exercise Layout Builder form actions; these focused Chromium fixtures exercise the captured reader presentation with actual module/core assets and explicit layout/content stress variants.

- Actual composed markup and one-, two-, three- and four-column core region variants; one protected document H1 and an owned wrapper independent of theme classes.
- Full-width sticky navigation clearing a simulated 112-pixel header; narrow regions stacking the internal reader sidebar and making it static; 320-pixel viewport, 200% text and JavaScript-disabled reading.
- A wide scientific table scrolling locally and a scientific figure retaining its native proportions.
- Linear print output: captured native output and the explicit stress fixture each produced 3 pages, with zero browser page errors. The native print pages were also rendered and visually inspected during focused development.

The variants deliberately rearrange captured markup and add stress content; they do not certify all third-party layouts/blocks, the JavaScript Layout Builder drag interface or the installed production theme. See [the shared-layout guide](docs/LAYOUT_BUILDER.md) for supported composition and staging checks.

## Installed-Drupal browser baseline — 1.0.1

These checks used the installed 1.0.1 Drupal pages. They are the full application browser baseline, separate from the 1.0.2 adapter fixture below.

**Authoring:** logged in, used CKEditor and native AJAX controls, added/duplicated/moved/removed teaching components and steps, uploaded a real diagram with alternative text/caption, saved a draft, reopened it with stable identities, previewed it, assigned it to a newly created chapter, reviewed and published it. An anonymous Read all request then returned HTTP 200 with the saved steps, image and typeset equation. No browser page errors were recorded.

The second review repeated the actual authoring journey with the revised controls: numbered outline, disclosure retention, action focus, hidden-field validation recovery and independently editable duplicates. At 390 pixels the full editor had no horizontal overflow. A replacement draft remained private while the earlier public text stayed available; the workbench displayed readiness and pending status, and reviewed publication replaced the public version successfully.

**Reader:** desktop and 320-pixel phone layouts, authenticated private progress controls, dark preference and explicit light override, keyboard activation/focus and browser history, JavaScript-disabled reading/navigation, wide table scrolling, canonical chapter rendering, one document H1, original-image links and native disclosure behavior.

The revised reader also passed checks for separate Core/Optional routes, path-local numbering, compact phone navigation, uncropped figure proportions, a consistent prose measure and a single progress summary/meter. The desktop outline respected a simulated fixed header, kept keyboard-focused links visible through its own scroll area and became static when the viewport could not accommodate it. Wide tables scrolled locally without page overflow, and the no-JavaScript summary remained available in its native position.

**Print:** continuous content, local typeset stacked fractions, figures/captions, expanded practice answers without JavaScript, hidden navigation/private progress controls, A4 page layout and headings kept with solution text. Internal rendered pages were visually inspected.

**Mathematics:** actual equation containers, assistive MathML and local WOFF2 fonts loaded successfully. The standalone local smoke rendered two equations and two assistive MathML elements; reader checks also confirmed typesetting. There were no external HTTP requests. MathJax used a local `blob:` accessibility worker. Upstream npm archive integrity values matched the fetched MathJax/font packages.

## Focused theme-adapter fixture — 1.0.2

The Chromium/Playwright fixture used `actools_bs5` main pinned at `1f711daa630a2243b8e1a68edf798a367382f17b`. It combined the current module's actual Twig reader with actual Drupal Functional editor HTML and the audited main header CSS, page DOM and submit-button classes. This validates the adapter against those pinned contracts; the full installed-theme runtime remains a staging check.

- Header/admin clearance aligned anchors and the sticky outline. Header growth disabled and restored sticking as available space changed.
- A preexisting native header variable prevented fallback attachment. Later native variables or the root class handed control back to the native contract; each signal acted independently and native values were preserved.
- The 320-pixel layout had no page overflow. The phone outline stayed a native, static disclosure and preserved its open state on resize. Canvas preview, sticky-off, print/return-to-screen and behavior detach cleaned up the adapter's owned state.
- Save draft and builder controls retained secondary styling through hover, keyboard focus, disabled and dark states. Publish and unrelated submit controls retained the theme's primary styling. Without JavaScript, native navigation and secondary CSS remained available; header measurement was unavailable by design.
- Zero browser page errors and zero idle reader style-attribute writes during the 300 ms observation window. The adapter made no global-header or native-header-variable writes.

## Deployment limits

PHP 8.4 for this new release, Percona, the production BOA/Nginx deployment, the full installed `actools_bs5` theme runtime and production content-security policy were not tested here. The pinned-contract fixture is not certification of that deployment. Run the module on a staging copy of that stack before deploying. Check toolbar/header offsets, theme overrides, locally served fonts/scripts/styles and the MathJax worker policy there.

The module declares Drupal 11.3+ / PHP 8.3+ compatibility; the exact exercised versions are the table above. This is not a formal screen-reader, WCAG, performance/load, multilingual or penetration-test certification. Private-file authoring, assessment delivery and DOCX round trips are outside this release's implemented scope.
