# Validation — Actools Lessons 1.0.2

The 1.0.2 automated suites and focused theme-adapter fixture were executed on 17 October 2026. The full installed-Drupal Chromium workflows recorded below establish the 1.0.1 baseline; the new 1.0.2 adapter has separate, explicitly scoped fixture evidence.

## Final automated results

| Suite | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| Unit | 7 | 50 | Passed |
| Drupal Kernel | 29 | 344 | Passed |
| Drupal Functional, native forms without JavaScript | 24 | 373 | Passed |
| **Total** | **60** | **767** | **Passed** |

The final 1.0.2 Kernel and Functional tests used separate local SQLite databases. The shipped `tests/run-functional.sh` was executed against the actual 1.0.2 source: its four Functional classes ran serially, each with a fresh HTTP server and its own database, while the base site's settings remained fixed. JUnit aggregate counts matched the individual test cases. All 60 tests passed, with zero failures, errors or skipped cases.

During the earlier 1.0.1 review, combined Functional attempts were rejected because occasional requests reached the primary local demo site instead of the isolated test fixture. Response HTML confirmed the wrong site; the precise cookie-dispatch cause was not established. The final 1.0.2 fresh-server class runs passed with the assertions unchanged. This record does not claim a clean single-process directory run. PHP source lint, module JavaScript syntax checks and Composer metadata validation also passed for the 1.0.2 repository source.

## Verified environment

| Component | Actual version |
| --- | --- |
| Drupal | 11.4.10 |
| PHP | 8.3.6 |
| Database | SQLite |
| PHPUnit | 11.5.57 |
| Drush | 13.8.0 |
| Browser used for baseline actual UI checks | Chromium 153.0.8010.0 through Playwright |
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

See `tests/README.md` for execution instructions and the test source for exact assertions.

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

PHP 8.4, Percona, the production BOA/Nginx deployment, the full installed `actools_bs5` theme runtime and production content-security policy were not tested here. The pinned-contract fixture is not certification of that deployment. Run the module on a staging copy of that stack before deploying. Check toolbar/header offsets, theme overrides, locally served fonts/scripts/styles and the MathJax worker policy there.

The module declares Drupal 11.3+ / PHP 8.3+ compatibility; the exact exercised versions are the table above. This is not a formal screen-reader, WCAG, performance/load, multilingual or penetration-test certification. Private-file authoring, assessment delivery and DOCX round trips are outside this release's implemented scope.
