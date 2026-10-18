# Validation — Actools Lessons 1.3.0

The 1.3.0 candidate passed all native stages of the shipped `tests/run-ci.sh all` entry point on 18 October 2026, then the complete browser stage through `tests/run-ci.sh browser`. Production source, assets and native tests stayed frozen. The browser fixture's dimensions were corrected for the rebuilt demo image before its final frozen run; release documentation and the source manifest were finalized afterwards.

## Final native results

| Suite | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| Unit | 21 | 82 | Passed |
| Drupal Kernel | 89 | 1494 | Passed |
| Drupal Functional, native forms and real AJAX | 58 | 1165 | Passed |
| **Total** | **168** | **2741** | **Passed** |

Zero native failures, errors or skips. JUnit aggregate counts matched individual cases; every Functional file ran. Native stages ran once without retries. The final browser run passed all nine regression groups with zero page errors. Earlier browser runtime/fixture failures are retained separately and are not counted as passes. Functional files had separate servers and SQLite database paths under `/tmp`; no other native test tasks ran concurrently.

## Environment and provisioning

The disposable minimal-profile site used fresh Composer-installed Drupal core/core-dev 11.4.10, Drush 13.8.0 and PHPUnit 11.5.57, with PHP 8.3.6, SQLite 3.45.1 and Composer 2.10.3. Dependencies were installed through the shipped bootstrap, without copying a prior vendor tree. The passing local browser run used Playwright 1.62.1 from the available global package and transient serverless Chromium 153.0.8010.0. This was not a fresh local `npm ci`; the hosted workflow performs its own pinned install. Browser runtime packages are not module dependencies.

The main runner and isolated PHP children used the runtime's PHPRC startup configuration: `max_input_vars=4096`, `post_max_size=128M`, `upload_max_filesize=20M`, `memory_limit=768M`, errors logged with display disabled. Empty upload controls are not treated as actual uploaded files. No module runtime dependency or MathJax/font bundle was changed.

First-party PHP, JavaScript/MJS, shell and Python syntax, Composer metadata, Twig grammar and local Markdown file targets passed. Twig grammar checks register Drupal symbol names; native render tests provide actual rendering coverage. The workflow is byte-identical to the published 1.2.0 workflow; local actionlint was not rerun. Its new 1.3.0 hosted commit had not yet run at preparation: check Actions for the exact published SHA. Both PHP jobs passed for published 1.2.0.

## New regression coverage

- Conditional external scalar controls and disabled ancestor groups, including the Form API states pattern used by automatic-alias widgets; owned teaching controls and signed inventories remain mandatory. This is not an installed Pathauto acceptance test.
- More than 20 empty native/external upload controls, save/reopen and actual builder AJAX; retained real-parser truncation, omitted first/middle/last controls, stale inventories and discarded oversized-body protection; wrong-form POSTs without a save.
- Strict scalar teaching changes versus canonical object-key equivalence; native review reset, publication and learner revisit; removed/reintroduced steps and formerly-default/nondefault native historical restore. A reproduced older-private-revision identity bypass is rejected, while immutable private restores receive a fresh version.
- Retained-history source loading once for 40 sibling steps, with fresh checks on a later operation of the same object; no permanent entity memoization.
- Figure-only file identities, paired practice reference validation, exact native save/reopen/copy/clear, empty legacy metadata no-op, context-bound revision signatures and safely rejected older signatures.
- Equation labels rendered as literal text with accessible associations, retained UUIDs and consistent guided/continuous/Print output after reorder.
- Real diagram-style install/update, existing operator-style preservation, GD derivative dimensions without crop/upscale, original-file checksum/link retention and missing-style cache fallback.

Earlier focused development failures remain separate from this final pass. One history-query test used a nonexistent logger-stop helper and was corrected. Initial input fixtures compared pre-hydration integer columns with stored string columns; the comparison was corrected to use the saved baseline. Several focused runs using workspace database paths failed with physical SQLite schema errors, including before lesson editing; the identical full class and exact runner passed after only the results/database location changed to `/tmp`. The underlying corruption cause was not proven. The final complete run used `/tmp` alone. The independent historical-private-source probe first reproduced an unsafe version reuse; its implementation was then corrected and regression-tested.

## Retained browser regressions and fixture correction

The final browser-only common-runner stage passed the retained suite. It uses actual Drupal `drupal.js`/`once`, module assets and local MathJax. It covers root/late attachment, private progress replacement, six print palettes, successful and failed/missing/blocked typesetting with raw TeX retained, individual figure links and theme header handoff.

The initial local browser launches failed on missing execute permission. After that was corrected, the ordinary headless-shell 151 runtime crashed with SIGTRAP on a later isolated context; a module-free multi-context smoke reproduced that runtime failure. Serverless Chromium 153 passed the same context smoke. Its first suite run exposed stale 1073 × 623 dimensions in the Layout Builder stress fixture, while the rebuilt demo PNG is 1800 × 960. The fixture was corrected to assert the actual decoded dimensions, preserve its original ratio tolerance against intrinsic proportions and retain `object-fit: contain`; no cropping assertion was removed. The final frozen browser suite then passed. Captured native HTML remains unchanged; provenance is recorded in the fixtures README.

Shared Layout Builder fixtures cover captured native output plus explicit 1/2/3/4-column variants, narrow-region stacking, 320-pixel reading, 200% text, no JavaScript and linear print. Figure fixtures cover closed solution images, once-only captions, anchor reveal, uncropped narrow rendering, browser-print restoration and no-JavaScript dedicated Print. The button fixture verifies secondary Unpublish and primary Publish styling.

These retained captures originate in earlier releases. New 1.3.0 derivative URLs and equation labels are verified through native Kernel/Functional tests, not newly captured full-theme Chromium authoring. This suite does not claim an installed production theme, real Layout Builder drag interface, BigPipe runtime, complete source corpus or full manuscript import.

## Historical installed-UI evidence

The following remains the earlier installed-Drupal browser baseline and pinned theme fixture, not a new 1.3.0 live-site journey.

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

The actual BOA/Nginx/Percona deployment, concurrent Percona transactions/deadlocks, production access modules, installed `actools_bs5`, toolbar/header offsets, private-file extension and CSP require staging acceptance. Verify local fonts/scripts/styles and MathJax's accessibility worker policy there. No production deployment was performed.

Drupal 11.3+ / PHP 8.3+ is declared compatibility; this local run exercises the exact versions above. It is not exhaustive screen-reader/WCAG, performance/load, multilingual, penetration-test or full-corpus scientific certification. Import approval, licensing/provenance, manuscript corrections, question-bank execution and Word/OMML round trips remain outside this module release. No Paragraphs work was included.
