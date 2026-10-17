/** Progressive disclosure for the native, server-backed lesson builder. */
((Drupal, once) => {
  const widgets = (context) => [
    ...(context.matches?.('.actools-author') ? [context] : []),
    ...context.querySelectorAll('.actools-author'),
  ];
  const remember = (widget) => {
    ['step', 'component'].forEach((kind) => {
      const input = widget.querySelector(`[data-actools-open-${kind}s]`);
      if (input) input.value = Array.from(widget.querySelectorAll(`details[data-actools-${kind}][open]`))
        .map((details) => details.dataset[kind === 'step' ? 'actoolsStep' : 'actoolsComponent']).join(',');
    });
  };
  const reveal = (target, widget) => {
    let parent = target.closest('details');
    while (parent && widget.contains(parent)) {
      parent.open = true;
      parent = parent.parentElement.closest('details');
    }
    remember(widget);
  };
  Drupal.behaviors.actoolsLessonAuthor = {
    attach(context) {
      once('actools-lesson-author', widgets(context)).forEach((widget) => {
        widget.addEventListener('toggle', () => remember(widget), true);
        widget.addEventListener('invalid', (event) => reveal(event.target, widget), true);
        widget.addEventListener('click', (event) => {
          if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
          const link = event.target.closest('[data-actools-jump]');
          if (!link) return;
          const step = Array.from(widget.querySelectorAll('[data-actools-step]'))
            .find((candidate) => candidate.dataset.actoolsStep === link.dataset.actoolsJump);
          if (step) {
            event.preventDefault();
            reveal(step, widget);
            // The browser's default fragment navigation can clear summary
            // focus. Keep the link/history behavior, then focus deliberately.
            if (window.location.hash !== link.hash) window.history.pushState(null, '', link.hash);
            step.scrollIntoView({ block: 'start' });
            step.querySelector('summary')?.focus({ preventScroll: true });
          }
        });
        widget.addEventListener('input', (event) => {
          const step = event.target.closest('[data-actools-step]');
          if (!step || event.target.matches('[data-actools-reviewed], [name$="[weight]"], [name$="[new_type]"]')) return;
          const reviewed = step.querySelector('[data-actools-reviewed]');
          if (reviewed) reviewed.checked = false;
          if (event.target.matches('[name$="[title]"]')) {
            const link = Array.from(widget.querySelectorAll('[data-actools-jump]'))
              .find((candidate) => candidate.dataset.actoolsJump === step.dataset.actoolsStep);
            if (link) link.textContent = event.target.value.trim() || Drupal.t('New learning step');
          }
        });
        // Core opens native validation errors on the server. Also cover client
        // validation and themes that add aria-invalid after server rendering.
        const invalid = widget.querySelector('[aria-invalid="true"], input.error, textarea.error, select.error');
        if (invalid) reveal(invalid, widget);
        const key = widget.dataset.actoolsFocus;
        const target = invalid || (key && Array.from(widget.querySelectorAll('[data-actools-focus-key]'))
          .find((candidate) => candidate.dataset.actoolsFocusKey === key));
        if (target) {
          reveal(target, widget);
          requestAnimationFrame(() => target.focus({ preventScroll: false }));
        }
        remember(widget);
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'serialize') widgets(context).forEach(remember);
    },
  };
})(Drupal, once);
