// Handles toggling between light and dark themes and persists the choice.
const initThemeToggle = () => {
  const button = document.getElementById('theme-toggle');
  if (!button) return;
  const icon = button.querySelector('i');

  const applyTheme = theme => {
    if (theme === 'dark') {
      document.body.classList.add('dark');
      icon.classList.remove('fa-moon');
      icon.classList.add('fa-sun');
    } else {
      document.body.classList.remove('dark');
      icon.classList.remove('fa-sun');
      icon.classList.add('fa-moon');
    }
  };

  const saved = localStorage.getItem('theme') || 'light';
  applyTheme(saved);

  button.addEventListener('click', () => {
    const newTheme = document.body.classList.contains('dark') ? 'light' : 'dark';
    applyTheme(newTheme);
    localStorage.setItem('theme', newTheme);
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initThemeToggle);
} else {
  initThemeToggle();
}
