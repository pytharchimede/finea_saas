document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-academy-quiz-form]').forEach(function (form) {
    const questions = Array.from(form.querySelectorAll('[data-academy-question]'));
    const submit = form.querySelector('[data-academy-submit]');
    function refreshSubmit() {
      if (submit) submit.disabled = questions.some(q => q.dataset.validated !== '1');
    }
    questions.forEach(function (question) {
      const button = question.querySelector('[data-academy-validate-question]');
      const correction = question.querySelector('.academy-correction');
      button && button.addEventListener('click', function () {
        const inputs = Array.from(question.querySelectorAll('input[type="radio"],input[type="checkbox"]'));
        const selected = inputs.filter(i => i.checked);
        if (selected.length === 0) { alert('Choisissez au moins une réponse avant de valider.'); return; }
        question.querySelectorAll('.academy-answer').forEach(function (label) {
          label.classList.remove('is-correct','is-wrong');
          const input = label.querySelector('input');
          const isCorrect = label.dataset.correct === '1';
          if (isCorrect) label.classList.add('is-correct');
          if (input && input.checked && !isCorrect) label.classList.add('is-wrong');
        });
        inputs.forEach(i => i.disabled = true);
        question.dataset.validated = '1';
        if (correction) correction.hidden = false;
        button.textContent = 'Réponse validée';
        button.disabled = true;
        refreshSubmit();
      });
    });
    refreshSubmit();
  });
});
