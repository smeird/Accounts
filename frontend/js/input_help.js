// Displays popover help for inputs with a data-help attribute.
document.addEventListener('DOMContentLoaded', () => {
    const helpBox = document.createElement('div');
    helpBox.id = 'input-help';
    helpBox.className = 'absolute bg-white border p-2 rounded shadow hidden z-50 text-sm';
    document.body.appendChild(helpBox);

    // Display the help popover near the focused input
    function showHelp(input) {
        helpBox.textContent = input.dataset.help;
        const rect = input.getBoundingClientRect();
        helpBox.style.left = (rect.left + window.scrollX) + 'px';
        helpBox.style.top = (rect.bottom + window.scrollY) + 'px';
        helpBox.classList.remove('hidden');
    }

    // Hide the help popover
    function hideHelp() {
        helpBox.classList.add('hidden');
    }

    // Allow other scripts to attach help to dynamically created inputs
    window.initInputHelp = function(root = document) {
        root.querySelectorAll('input[data-help], select[data-help]').forEach(el => {
            el.addEventListener('focus', () => showHelp(el));
            el.addEventListener('blur', hideHelp);
            el.addEventListener('mouseenter', () => showHelp(el));
            el.addEventListener('mouseleave', hideHelp);
        });
    };

    // Initialise for existing elements
    window.initInputHelp();
});
