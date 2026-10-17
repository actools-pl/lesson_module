# Changelog

## 1.0.2 — 2026-10-17

Publish the standalone Drupal module at `actools-pl/lesson_module`, with installation, BOA deployment, editor, operations and contributor guidance. Integrate the current `actools_bs5` sticky header through a scoped compatibility adapter that defers to the theme's native offset contract. Preserve secondary authoring controls when the theme adds Bootstrap primary styling. No database schema change; existing content, revisions, IDs and progress are retained.

## 1.0.1 — 2026-10-17

Focused product review: separate Core/Optional routes, compact mobile navigation, consistent reading/figure measures, conditional persistent desktop outline, semantic orientation lists and one private accessible reading meter. Guided reading has one primary action; Read all changes only explicitly selected step marks.

Authoring gains a numbered outline, retained section state, action focus, hidden-error recovery and independent duplicate-component editing. Native Save draft preserves public content; explicit reviewed publication, revision snapshots and latest-draft access checks protect editing. The workbench shows assignment, readiness and pending state. Existing core Content Moderation remains authoritative. Existing content, UUIDs, revisions and progress are retained; upgrade requires cache rebuild, not a reinstall.

## 1.0.0 — 2026-10-17

First installable release of Actools Lessons: module-owned guided reader and chapter landing page; separate lesson/step navigation; native revision-owned structured authoring; eight component types; local mathematical typesetting; continuous/print views; explicit private progress and resume; compatible Topic/Resource setup; optional original Surface Tension example; actual Drupal tests.

Implementation decision: core-only custom structured field rather than new Paragraphs dependencies. Stable step/component identities and full native node revisions preserve the future migration boundary. Existing themes, starter recipes, role grants, taxonomy seeds and legacy resources are not silently changed.
