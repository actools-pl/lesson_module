/**
 * Compatibility for actools_bs5 main before its native header offset contract.
 * Only module shells receive a private offset. The theme owns the header,
 * document classes, native variables, and document scroll padding throughout.
 */
(function (Drupal) {
  'use strict';

  const selector = '.actools-lesson, .actools-chapter, .actools-author-workbench, .actools-managed-form';
  const property = '--actools-lesson-header-offset';
  const shells = new Map();
  const printMedia = window.matchMedia('print');
  let printing = false;
  let scheduled = 0;
  let running = false;
  let watched = [];
  const resize = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
  const mutations = new MutationObserver((records) => {
    // Ignore our own shell style writes, including reader eligibility changes.
    // This avoids a measurement/mutation loop while still noticing late native
    // contract styles, header replacements and sticky/Canvas setting changes.
    if (records.some((record) => record.type === 'childList'
      || record.target === document.documentElement
      || record.target === document.body
      || record.target.matches('.page, .page > header[role="banner"], #toolbar-bar, .toolbar-tray-horizontal, .gin-secondary-toolbar'))) {
      schedule();
    }
  });

  function inCanvas() {
    if (document.documentElement.classList.contains('is-canvas-preview')) return true;
    try {
      return Boolean(window.frameElement && 'canvasPreview' in window.frameElement.dataset);
    }
    catch (error) {
      return false;
    }
  }

  function nativeContract() {
    const root = document.documentElement;
    const style = window.getComputedStyle(root);
    return root.classList.contains('actools-sticky-header-active')
      || style.getPropertyValue('--actools-header-height').trim() !== ''
      || style.getPropertyValue('--actools-header-admin-offset').trim() !== '';
  }

  function setOffset(shell, value) {
    const previous = shells.get(shell);
    if (previous === value) return;
    if (value === null) shell.style.removeProperty(property);
    else shell.style.setProperty(property, value);
    shells.set(shell, value);
    shell.dispatchEvent(new CustomEvent('actools:header-offset-change'));
  }

  function update() {
    scheduled = 0;
    const header = document.querySelector('.page > header[role="banner"]');
    const bars = Array.from(document.querySelectorAll('#toolbar-bar, .toolbar-tray-horizontal.is-active, .gin-secondary-toolbar'));
    const nextWatched = [header, ...bars].filter(Boolean);
    if (resize && (nextWatched.length !== watched.length || nextWatched.some((element, index) => element !== watched[index]))) {
      resize.disconnect();
      nextWatched.forEach((element) => resize.observe(element));
      watched = nextWatched;
    }
    let value = null;
    if (!printing && !printMedia.matches && !inCanvas() && !nativeContract() && header) {
      const style = window.getComputedStyle(header);
      const rect = header.getBoundingClientRect();
      if ((style.position === 'sticky' || style.position === 'fixed')
        && style.display !== 'none' && style.visibility !== 'hidden' && rect.height > 0) {
        // Measure the occupied viewport edge rather than adding an admin bar a
        // second time. Main's mobile header uses top:0; its desktop header uses
        // Drupal displacement. Clamp a not-yet-stuck header to its sticky edge.
        const top = parseFloat(style.top) || 0;
        let clearance = Math.max(0, Math.min(rect.bottom, Math.max(0, top) + rect.height));
        const displaced = parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue('--drupal-displace-offset-top'));
        if (Number.isFinite(displaced)) clearance = Math.max(clearance, displaced);
        bars.forEach((bar) => {
          const barStyle = window.getComputedStyle(bar);
          const bounds = bar.getBoundingClientRect();
          if ((barStyle.position === 'fixed' || barStyle.position === 'sticky')
            && barStyle.display !== 'none' && barStyle.visibility !== 'hidden'
            && bounds.width >= window.innerWidth * 0.5 && bounds.height > 0
            && bounds.top <= clearance + 1 && bounds.bottom > 0) {
            clearance = Math.max(clearance, bounds.bottom);
          }
        });
        value = `${Math.ceil(clearance)}px`;
      }
    }
    shells.forEach((previous, shell) => {
      const inEditor = shell.closest('.layout-builder, .ui-dialog');
      setOffset(shell, shell.isConnected && !inEditor ? value : null);
    });
  }

  function schedule() {
    if (running && !scheduled) scheduled = window.requestAnimationFrame(update);
  }

  function beforePrint() {
    printing = true;
    update();
  }

  function afterPrint() {
    printing = false;
    schedule();
  }

  function start() {
    if (running) return;
    running = true;
    mutations.observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class'], childList: true, subtree: true });
    window.addEventListener('resize', schedule, { passive: true });
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('beforeprint', beforePrint);
    window.addEventListener('afterprint', afterPrint);
    document.addEventListener('load', schedule, true);
    if (printMedia.addEventListener) printMedia.addEventListener('change', schedule);
  }

  function stop() {
    running = false;
    mutations.disconnect();
    if (resize) resize.disconnect();
    watched = [];
    if (scheduled) window.cancelAnimationFrame(scheduled);
    scheduled = 0;
    window.removeEventListener('resize', schedule);
    window.removeEventListener('scroll', schedule);
    window.removeEventListener('beforeprint', beforePrint);
    window.removeEventListener('afterprint', afterPrint);
    document.removeEventListener('load', schedule, true);
    if (printMedia.removeEventListener) printMedia.removeEventListener('change', schedule);
  }

  Drupal.behaviors.actoolsLessonBs5Integration = {
    attach(context) {
      const found = [];
      if (context.matches && context.matches(selector)) found.push(context);
      if (context.querySelectorAll) found.push(...context.querySelectorAll(selector));
      found.forEach((shell) => {
        if (!shells.has(shell)) shells.set(shell, null);
      });
      if (shells.size) {
        start();
        // Native PR3 is checked synchronously on attach as well as on every
        // subsequent observation. No compatibility offset survives a handoff.
        update();
      }
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') return;
      shells.forEach((value, shell) => {
        if (context === shell || (context.contains && context.contains(shell))) {
          setOffset(shell, null);
          shells.delete(shell);
        }
      });
      if (!shells.size) stop();
    },
  };
})(Drupal);
