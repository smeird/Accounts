// Detect whether the user agent represents macOS
function isMacOS(ua) {
    ua = ua || (typeof navigator !== 'undefined' ? navigator.userAgent : '');
    return /Mac OS X/i.test(ua);
}

// Configure the upload form and progress display
function initUpload() {
    const form = document.querySelector('form');
    if (!form) return;
    const fileInput = form.querySelector('input[type="file"]');
    const progressContainer = document.getElementById('progress-container');

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const files = fileInput.files;
        if (isMacOS()) {
            progressContainer.innerHTML = '';
            Array.from(files).forEach((file) => {
                const container = document.createElement('div');
                container.className = 'space-y-1';

                const wrapper = document.createElement('div');
                wrapper.className = 'w-full bg-gray-200 rounded h-2';
                const bar = document.createElement('div');
                bar.className = 'bg-indigo-600 h-2 rounded';
                bar.style.width = '0%';
                wrapper.appendChild(bar);

                const status = document.createElement('div');
                status.className = 'text-xs text-gray-700';
                status.textContent = 'Uploading…';

                container.appendChild(wrapper);
                container.appendChild(status);
                progressContainer.appendChild(container);

                const fd = new FormData();
                fd.append('ofx_files[]', file, file.name);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', form.action);
                xhr.upload.onprogress = (evt) => {
                    if (evt.lengthComputable) {
                        bar.style.width = (evt.loaded / evt.total * 80) + '%';
                    }
                };
                xhr.upload.onload = () => {
                    status.textContent = 'Tagging transactions…';
                    bar.style.width = '90%';
                };
                xhr.onload = () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        bar.style.width = '100%';
                        bar.classList.add('bg-green-600');
                        status.textContent = 'Categorising transactions…';
                        setTimeout(() => {
                            status.textContent = xhr.responseText;
                            showMessage(xhr.responseText);
                        }, 300);
                    } else {
                        bar.classList.add('bg-red-600');
                        status.textContent = 'Upload failed';
                        showMessage('Upload failed', 'error');
                    }
                };
                xhr.send(fd);
            });
        } else {
            const data = new FormData(form);
            fetch(form.action, { method: 'POST', body: data })
                .then((resp) => resp.text())
                .then(showMessage)
                .catch(() => showMessage('Upload failed', 'error'));
        }
    });
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { isMacOS, initUpload };
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initUpload);
}
