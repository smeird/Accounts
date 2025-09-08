// Handles toggling between light and dark themes and persists the choice.

const initThemeToggle = () => {
  const button = document.getElementById('theme-toggle');
  const icon = button ? button.querySelector('i') : null;
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

  const applyTheme = theme => {
    if (theme === 'dark') {
      document.body.classList.add('dark');
      document.documentElement.classList.add('dark');
      if (icon) {
        icon.classList.remove('fa-moon');
        icon.classList.add('fa-sun');
      }
    } else {
      document.body.classList.remove('dark');
      document.documentElement.classList.remove('dark');
      if (icon) {
        icon.classList.remove('fa-sun');
        icon.classList.add('fa-moon');
      }
    }
    document.dispatchEvent(new CustomEvent('themechange', { detail: theme }));
  };

  const saved = localStorage.getItem('theme');
  const systemTheme = prefersDark.matches ? 'dark' : 'light';
  applyTheme(saved || systemTheme);

  prefersDark.addEventListener('change', e => {
    if (!localStorage.getItem('theme')) {
      applyTheme(e.matches ? 'dark' : 'light');
    }
  });

  if (button) {
    button.addEventListener('click', () => {
      const newTheme = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
      applyTheme(newTheme);
      if (newTheme === (prefersDark.matches ? 'dark' : 'light')) {
        localStorage.removeItem('theme');
      } else {
        localStorage.setItem('theme', newTheme);
      }
    });
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initThemeToggle);
} else {
  initThemeToggle();
}
