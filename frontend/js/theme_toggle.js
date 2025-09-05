// Handles toggling between light and dark themes and persists the choice.

const initThemeToggle = () => {
  const button = document.getElementById('theme-toggle');
  const icon = button ? button.querySelector('i') : null;

  const applyTheme = theme => {
    if (theme === 'dark') {
      document.body.classList.add('dark');
      if (icon) {
        icon.classList.remove('fa-moon');
        icon.classList.add('fa-sun');
      }
    } else {
      document.body.classList.remove('dark');
      if (icon) {
        icon.classList.remove('fa-sun');
        icon.classList.add('fa-moon');
      }
    }
  };

  const saved = localStorage.getItem('theme') || 'light';
  applyTheme(saved);

  if (button) {
    button.addEventListener('click', () => {
      const newTheme = document.body.classList.contains('dark') ? 'light' : 'dark';
      applyTheme(newTheme);
      localStorage.setItem('theme', newTheme);
    });
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initThemeToggle);
} else {
  initThemeToggle();
}
