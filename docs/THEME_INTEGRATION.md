# Theme integration — Actools Lessons 1.0.2

The module supplies the chapter and lesson experience. Drupal's active theme owns the page, global header, menus and branding. The module also works without `actools_bs5`; it has no hard dependency on that theme, Bootstrap, Layout Builder, a starter recipe or an open pull request.

The GitHub repository is `actools-pl/lesson_module`; the Drupal machine name and installed directory remain `actools_lesson`. See [INSTALLATION.md](INSTALLATION.md) for deployment and explicit root/site selection.

## Theme revisions inspected

The audit read these exact GitHub refs on 17 October 2026:

| Theme surface | Pinned revision | Status at audit |
| --- | --- | --- |
| [Main](https://github.com/actools-pl/actools_bs5/tree/1f711daa630a2243b8e1a68edf798a367382f17b) | `1f711daa630a2243b8e1a68edf798a367382f17b` | Current base |
| [PR #2 — Topic building blocks](https://github.com/actools-pl/actools_bs5/pull/2) | `6d65655a1302c91310714bfa7be1374af9e7bad9` | Open; targets main |
| [PR #3 — Header/navigation](https://github.com/actools-pl/actools_bs5/pull/3) | `069de07109125e1be277e57047f788fc9f67fb10` | Open; targets the PR #2 branch |

These are distinct targets. Installing the module does not merge either PR, apply a recipe, add menu links or configure the theme. Recheck the deployed theme revision before relying on a branch-specific behavior.

## Page and rendering ownership

The inspected theme delegates its page template to `actools_bs5:page`. Its structure includes `#page.page.d-flex.flex-column > header[role="banner"]`, a native Content region and a Footer region. Keep existing branding/menu blocks in Header; the module adds no competing global header.

On accessible managed lesson/chapter full views, the module owns the learning document and its H1. It suppresses Drupal's native page-title block on those managed view routes. Edit forms and ordinary resources retain native titles. Check any site-specific page template that directly prints another title.

The module's reader/chapter/component templates provide its default design. The theme's ordinary Topic/Resource node templates continue to serve unmanaged content and other view modes. Copying those node templates is not how to replace the managed reader. Check entity embeds, contributed displays/view modes and custom preprocess hooks that alter this boundary.

The main theme defaults Content to Bootstrap `container`, which can be narrower than the reader's own maximum width. This is normal composition. Align Header and Content through theme settings; do not remove all page containers globally to widen one lesson.

## Header clearance and the optional adapter

Version 1.0.2 provides `actools_lesson/bs5_integration`. `ThemeIntegration::attach()` adds this library only for active `actools_bs5` or a descendant, and only to managed reader/chapter/editor or workbench output. Other themes retain the independent default design.

Main's **Sticky header** setting defaults to enabled. Its script adds a shadow but does not publish measured header height or root scroll padding. Without a compatibility measurement, an anchored step or figure can land beneath that header.

| Offset owner | Contract | Module behavior |
| --- | --- | --- |
| Native theme, present in inspected PR #3 | Root `--actools-header-height`, `--actools-header-admin-offset` and `actools-sticky-header-active`; the theme supplies root scroll padding. | Defer to native offsets. Use them for the outline and keep only a small target margin, avoiding a second header-height addition. |
| Main-branch compatibility fallback | Module-owned `--actools-lesson-header-offset`, scoped to module wrappers. | Measure visible occupied top clearance when a real sticky/fixed theme header needs it. Never write the theme's native offset variables or active class. |

The fallback observes changing header/admin geometry and relinquishes its value when native offsets appear, including after later behavior attachment. It clears its measurement when sticky positioning is off, during print or Canvas preview, and on detach. It does not reposition the header, register it as another Drupal displacement element or replace the theme's sticky/menu scripts.

Its scopes are `.actools-lesson`, `.actools-chapter`, `.actools-author-workbench` and `.actools-managed-form`. Do not install another site-wide header measurement script for the lesson. A custom header with a different selector needs a reviewed adapter rather than a guessed fixed height.

Dynamic measurement is a JavaScript enhancement. Without JavaScript, ordinary lesson navigation and disclosures remain available, but exact clearance under a variable-height sticky header needs a site/theme decision. Test that case explicitly; disabling Sticky header is a simple fallback when the theme cannot supply suitable no-JavaScript clearance.

## Author buttons and branding

The inspected theme's `templates/form/input--submit.html.twig` adds `btn btn-primary` to every native submit. This can make Save draft, Move and Duplicate as prominent as Publish. The conditional integration styles keep builder actions and the editorial secondary action visually secondary while retaining Publish as primary. Form API values, handlers, validation, access and permissions remain unchanged. Learner progress uses a module-owned native button rather than that input template.

Brand through a small theme/subtheme library after the reader library instead of duplicating the complete reader template. Useful shell tokens are:

| Tokens | Purpose |
| --- | --- |
| `--al-sans`, `--al-serif` | Prose/control and display-heading font stacks |
| `--al-ink`, `--al-muted`, `--al-paper`, `--al-soft` | Text and surface colors |
| `--al-accent`, `--al-accent-hover`, `--al-on-accent` | Links/actions and their foreground color |
| `--al-border`, `--al-focus`, `--al-radius` | Boundaries, focus and corners |
| `--al-reading-width`, `--al-figure-width`, `--al-figure-height` | Prose measure and uncropped figure bounds |

Defaults are declared on `.actools-lesson` and `.actools-chapter`. Setting only `:root --al-*` will not replace those declarations; override the shell selectors with suitable cascade order/specificity. Fonts/radius can map to the theme's `--bs-body-font-family` and `--bs-border-radius`. Review contrast before mapping arbitrary brand colors, including foreground, hover and explicit light/dark modes. The module recognizes `data-bs-theme` and `data-theme` preferences.

## Adopting an existing Topic or Resource

PR #2's starter storage is compatible with preflight: body `text_long`, summary `string_long`, and Resource kind `list_string` including `lesson`. The module adds missing compatible structure. It does not replace existing storage or convert legacy `kind=lesson` resources automatically. Resolve incompatible field types/cardinality/targets through a reviewed, data-preserving configuration change.

Managed-reader flags are deliberate opt-ins. Topic Layout Builder defaults and per-node overrides remain stored, but a managed full chapter renders the module's introduction, orientation and ordered lesson route. Arbitrary Layout Builder blocks and old `field_actools_resources` cards are not automatically displayed in that shell. The reserved chapter resources template slot does not import those cards.

Prefer a new managed chapter or review/migrate an existing Topic's teaching content before enabling its flag. Canonical lesson order is `field_actools_lessons`, separate from legacy card references. Unmanaged Topics/Resources keep their existing presentation. Do not merge or reapply the starter recipe simply to install or update the module.

PR #3's optional finder searches titles/summaries with kind/level filters. It is not a search of structured lesson steps. Check path/View conflicts and anonymous access before applying its recipe or adding a finder menu link.

## Optional shared Layout Builder presentation — 1.1.0

The optional `actools_lesson_layout_builder` add-on lets an explicitly opted-in Topic/Resource Default or Full display surround the complete reader with shared supplementary content blocks and menus. It keeps the existing theme/header contract and adds container-aware styles only inside its own layout/document wrappers. A narrow layout region collapses the reader's internal sidebar even on a wide screen.

The preceding standalone-reader behaviour remains the default without opt-in. Existing per-node overrides are preserved; managed public pages use the validated shared default instead, while ordinary unmanaged content keeps native applicable overrides and field blocks. Private preview/history and dedicated Study/Print stay with the existing reader. Shared layouts are site configuration with immediate shared effect, outside a lesson's draft/revision lifecycle. Follow [LAYOUT_BUILDER.md](LAYOUT_BUILDER.md) for the complete contract.

## Mathematics, print and content security

The module owns local mathematics typesetting inside its lesson document. The inspected theme libraries do not initialize MathJax. Coordinate one renderer if the actual site adds another math plugin.

The package uses no remote CDN. Check the actual CSP for local scripts/fonts, generated MathJax style elements and the local `blob:` accessibility worker used by the exercised configuration. The test server did not impose the production BOA policy. Adjust policy through normal security review rather than disabling it broadly.

Main's print library hides the global header/footer/admin controls. The dedicated module print view shows the same stored lesson steps and expands hints/solutions. Check wide tables, long figures and the site's print header with the actual browser/printer.

## Staging acceptance and evidence limits

Existing actual Drupal reader/editor/access tests and browser checks are recorded in [VALIDATION.md](../VALIDATION.md). The 1.0.2 adapter's focused header/button fixtures are separate evidence; they do not constitute a full installation of the production theme, recipes, BOA configuration or CSP.

On a staging clone with the deployed theme revision:

1. Check canonical and Study views, one H1, Core/Extension groups, legacy unmanaged content and any Layout Builder/custom display overrides.
2. Check sticky on/off as anonymous and with the real admin toolbar. Resize branding, open/close the phone menu, follow deep step/figure links and move keyboard focus to anchored controls. Test native offsets and the main-branch fallback without double clearance.
3. Inspect 320px/390px layouts, 200% text, explicit light/dark modes, short viewports and a growing header. The outline must become static when insufficient reading room remains.
4. Under the actual administration theme, save/reopen drafts, move/duplicate builder content, recover hidden-field errors, and verify Save draft versus Publish hierarchy. Keep Claro where the platform requires it.
5. Check private drafts against separate public content, warm caches as anonymous and two learners, Read all, no JavaScript, wide tables, full-resolution figures, math and Print.
6. Retain the theme's card/caption/image/download/navigation checks. Follow the managed-platform deployment/Verify process; repository tests do not perform live installation or BOA Verify.
