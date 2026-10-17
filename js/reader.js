/**
 * Progressive reader conveniences only. Links, disclosures and routes work
 * without JavaScript. This behavior never writes progress or replaces history.
 */
(function (Drupal, once) {
  'use strict';

  const printDetails = new Map();
  const readerCleanup = new WeakMap();

  function revealAnchor(reader) {
    if (!window.location.hash) return;
    let id;
    try {
      id = decodeURIComponent(window.location.hash.slice(1));
    }
    catch (error) {
      return;
    }
    const target = document.getElementById(id);
    if (!target || !reader.contains(target)) return;
    let ancestor = target.parentElement;
    while (ancestor && ancestor !== reader) {
      if (ancestor.tagName === 'DETAILS') ancestor.open = true;
      ancestor = ancestor.parentElement;
    }
  }

  Drupal.behaviors.actoolsLessonReader = {
    attach(context) {
      once('actools-reader', '[data-actools-reader]', context).forEach((reader) => {
        // CSS consumes the theme contract or an optional scoped adapter. The
        // reader never measures or replaces the site's global header.
        const smallScreen = window.matchMedia('(max-width: 75.99rem)');
        const disclosures = reader.querySelectorAll('[data-actools-disclosure]');
        const updateDisclosures = (initial = false) => {
          disclosures.forEach((details) => {
            if (details.contains(document.activeElement)) return;
            if (!smallScreen.matches) {
              details.open = true;
            }
            else if (initial) {
              // With JS disabled, open native details remain fully readable.
              details.open = false;
            }
          });
        };
        // Enhance only the existing chapter outline. The offset comes from
        // its computed CSS, which consumes the theme's header variables.
        // If the viewport cannot leave enough reading room, remain static.
        const chapter = reader.querySelector('.actools-chapter-nav');
        let scheduled = 0;
        const updateSticky = () => {
          scheduled = 0;
          if (!chapter || !reader.isConnected) return;
          const offset = parseFloat(window.getComputedStyle(chapter).insetBlockStart);
          const rootSize = parseFloat(window.getComputedStyle(document.documentElement).fontSize) || 16;
          const insideEditor = reader.closest('.is-canvas-preview, .layout-builder, .ui-dialog');
          const eligible = !smallScreen.matches && Number.isFinite(offset)
            && window.innerHeight - offset >= 20 * rootSize && !insideEditor;
          chapter.toggleAttribute('data-actools-sticky-ready', eligible);
        };
        const scheduleSticky = () => {
          if (!scheduled) scheduled = window.requestAnimationFrame(updateSticky);
        };
        const onMediaChange = () => { updateDisclosures(); scheduleSticky(); };
        const onHashChange = () => revealAnchor(reader);
        const headerVariables = new MutationObserver(scheduleSticky);
        headerVariables.observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class'] });
        updateDisclosures(true);
        updateSticky();
        if (smallScreen.addEventListener) smallScreen.addEventListener('change', onMediaChange);
        window.addEventListener('resize', scheduleSticky, { passive: true });
        reader.addEventListener('actools:header-offset-change', scheduleSticky);
        revealAnchor(reader);
        window.addEventListener('hashchange', onHashChange);
        readerCleanup.set(reader, () => {
          headerVariables.disconnect();
          if (scheduled) window.cancelAnimationFrame(scheduled);
          if (smallScreen.removeEventListener) smallScreen.removeEventListener('change', onMediaChange);
          window.removeEventListener('resize', scheduleSticky);
          reader.removeEventListener('actools:header-offset-change', scheduleSticky);
          window.removeEventListener('hashchange', onHashChange);
        });

        reader.querySelectorAll('[data-actools-print]').forEach((button) => {
          button.hidden = false;
          button.addEventListener('click', async () => {
            button.disabled = true;
            try {
              if (window.MathJax && window.MathJax.startup && window.MathJax.startup.promise) {
                await window.MathJax.startup.promise;
                if (window.MathJax.typesetPromise) await window.MathJax.typesetPromise([reader]);
              }
              if (document.fonts && document.fonts.ready) await document.fonts.ready;
              window.print();
            }
            catch (error) {
              // Keep the browser's native print available if math failed.
              window.print();
            }
            finally {
              button.disabled = false;
            }
          });
        });
      });
      // Browser print also expands closed answers when printing a guided view.
      // The dedicated print route already emits all steps and open answers.
      once('actools-print-events', 'html', context).forEach(() => {
        window.addEventListener('beforeprint', () => {
          document.querySelectorAll('.actools-lesson details, .actools-chapter details').forEach((details) => {
            printDetails.set(details, details.open);
            details.open = true;
          });
        });
        window.addEventListener('afterprint', () => {
          printDetails.forEach((open, details) => { details.open = open; });
          printDetails.clear();
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') return;
      const readers = [];
      if (context.matches && context.matches('[data-actools-reader]')) readers.push(context);
      if (context.querySelectorAll) readers.push(...context.querySelectorAll('[data-actools-reader]'));
      readers.forEach((reader) => {
        const cleanup = readerCleanup.get(reader);
        if (cleanup) cleanup();
        readerCleanup.delete(reader);
      });
    },
  };
})(Drupal, once);
