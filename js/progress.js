/**
 * Decorate the public step rail using only the private Form API placeholder.
 * All changes still happen through authenticated, CSRF-protected native POST.
 * There is deliberately no localStorage, GET mutation, fetch or account state
 * in Drupal.settings or the cached public reader shell.
 */
(function (Drupal, once) {
  'use strict';

  const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
  function ids(value) {
    try {
      const list = JSON.parse(value || '[]');
      return new Set(Array.isArray(list) ? list.filter((id) => typeof id === 'string' && uuid.test(id)) : []);
    }
    catch (error) {
      return new Set();
    }
  }

  Drupal.behaviors.actoolsLessonProgress = {
    attach(context) {
      const forms = [];
      if (context.matches && context.matches('[data-actools-completed-steps]')) forms.push(context);
      if (context.querySelectorAll) forms.push(...context.querySelectorAll('[data-actools-completed-steps]'));
      once('actools-private-progress', forms).forEach((form) => {
        const reader = form.closest('[data-actools-reader]');
        if (!reader) return;
        const completed = ids(form.getAttribute('data-actools-completed-steps'));
        const revisit = ids(form.getAttribute('data-actools-revisit-steps'));
        reader.querySelectorAll('[data-actools-step-link]').forEach((item) => {
          const id = item.getAttribute('data-actools-step-link');
          const isComplete = completed.has(id) && !revisit.has(id);
          item.classList.toggle('is-complete', isComplete);
          item.classList.toggle('needs-revisit', revisit.has(id));
          const status = item.querySelector('[data-actools-completion-label]');
          if (status) {
            status.hidden = !isComplete && !revisit.has(id);
            status.textContent = revisit.has(id) ? Drupal.t('Content changed; revisit this step') : Drupal.t('Marked as read');
          }
        });
        // Move the original private meter, retaining its label association.
        // Without JavaScript it stays below the lesson; it is never duplicated.
        const source = form.querySelector('.actools-progress__summary');
        const topSummary = reader.querySelector('[data-actools-progress-summary]');
        if (source && topSummary) {
          topSummary.replaceChildren(source);
          topSummary.hidden = false;
        }
      });
    },
  };
})(Drupal, once);
