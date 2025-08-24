document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('preview-url');
    const preview = document.getElementById('link-preview');
    if (!input || !preview) return;
    input.addEventListener('change', () => {
        const url = input.value.trim();
        if (!url) return;
        preview.textContent = 'Loading...';
        fetch('../php_backend/public/link_preview.php?url=' + encodeURIComponent(url))
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    preview.textContent = 'No preview available.';
                    return;
                }
                const card = document.createElement('div');
                card.className = 'flex space-x-4';
                if (data.image) {
                    const img = document.createElement('img');
                    img.src = data.image;
                    img.alt = '';
                    img.className = 'w-24 h-24 object-cover rounded';
                    card.appendChild(img);
                }
                const info = document.createElement('div');
                const title = document.createElement('h3');
                title.className = 'font-semibold';
                title.textContent = data.title || url;
                const desc = document.createElement('p');
                desc.className = 'text-sm text-gray-600';
                desc.textContent = data.description || '';
                info.appendChild(title);
                info.appendChild(desc);
                card.appendChild(info);
                preview.innerHTML = '';
                preview.appendChild(card);
            })
            .catch(() => {
                preview.textContent = 'No preview available.';
            });
    });
});
