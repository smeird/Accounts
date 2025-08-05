function isMacOS(ua) {
    ua = ua || (typeof navigator !== 'undefined' ? navigator.userAgent : '');
    return /Mac OS X/i.test(ua);
}

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
                const wrapper = document.createElement('div');
                wrapper.className = 'w-full bg-gray-200 rounded h-2';
                const bar = document.createElement('div');
                bar.className = 'bg-blue-600 h-2 rounded';
                bar.style.width = '0%';
                wrapper.appendChild(bar);
                progressContainer.appendChild(wrapper);

                const fd = new FormData();
                fd.append('ofx_files[]', file, file.name);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', form.action);
                xhr.upload.onprogress = (evt) => {
                    if (evt.lengthComputable) {
                        bar.style.width = (evt.loaded / evt.total * 100) + '%';
                    }
                };
                xhr.onload = () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        bar.classList.add('bg-green-600');
                        showMessage(xhr.responseText);
                    } else {
                        bar.classList.add('bg-red-600');
                        showMessage('Upload failed');
                    }
                };
                xhr.send(fd);
            });
        } else {
            const data = new FormData(form);
            fetch(form.action, { method: 'POST', body: data })
                .then((resp) => resp.text())
                .then(showMessage)
                .catch(() => showMessage('Upload failed'));
        }
    });
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { isMacOS, initUpload };
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', initUpload);
}
