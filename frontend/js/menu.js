// Dynamically loads the shared navigation menu into pages and ensures icon support.
// Override fetch globally to bypass browser caching.
const originalFetch = window.fetch;
window.fetch = (input, init = {}) => {
  init = init || {};
  if (!init.cache) {
    init.cache = 'no-store';
  }
  return originalFetch(input, init);
};

document.addEventListener('DOMContentLoaded', () => {
  const menu = document.getElementById('menu');
  if (menu) {
    // Add responsive classes so the navigation can toggle on small screens
    menu.classList.add(
      'hidden',
      'md:block',
      'fixed',
      'md:relative',
      'top-0',
      'left-0',
      'h-full',
      'overflow-y-auto',
      'z-50'
    );

    // Load Font Awesome for menu icons if not already loaded
    if (!document.getElementById('fa-icons')) {
      const link = document.createElement('link');
      link.id = 'fa-icons';
      link.rel = 'stylesheet';
      link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
      document.head.appendChild(link);
    }

    // Button to toggle the menu on mobile devices
    const toggle = document.createElement('button');
    toggle.id = 'menu-toggle';
    toggle.className =
      'md:hidden fixed top-4 left-4 bg-blue-600 text-white p-2 rounded shadow z-50';
    toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
    toggle.addEventListener('click', () => {
      menu.classList.toggle('hidden');
    });
    document.body.appendChild(toggle);

    fetch('menu.html')
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
        // Hide menu after clicking a link on mobile
        menu.querySelectorAll('a').forEach(a =>
          a.addEventListener('click', () => menu.classList.add('hidden'))
        );
      })
      .catch(err => console.error('Menu load failed', err));
  }

  // Apply Tailwind card styling to all sections or wrap main content in a card
  document.querySelectorAll('main').forEach(main => {
    const sections = main.querySelectorAll('section');
    if (sections.length > 0) {
      sections.forEach(section => {
        section.classList.add('bg-white', 'p-6', 'rounded', 'shadow');
      });
    } else {
      const wrapper = document.createElement('section');
      wrapper.className = 'bg-white p-6 rounded shadow';
      while (main.firstChild) {
        wrapper.appendChild(main.firstChild);
      }
      main.appendChild(wrapper);
    }
  });

  // Load page help overlay on every page
  const helpScript = document.createElement('script');
  helpScript.src = 'js/page_help.js';
  document.body.appendChild(helpScript);
});
