// Displays a brief keyboard navigation tip on each page.
document.addEventListener('DOMContentLoaded', () => {
  const hint = document.createElement('div');
  hint.textContent = 'Tip: Use Tab to move around, Shift+Tab to move back, Enter to activate and ? for help.';
  hint.className = 'fixed bottom-4 left-4 bg-indigo-600 text-white text-sm px-3 py-2 rounded shadow';
  document.body.appendChild(hint);
  setTimeout(() => hint.remove(), 8000);
});
