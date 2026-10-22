/* HDRJPG: starts conversions from a photo's details in the Media Library and follows them until they finish. */
(function () {
    const followed = new Set();

    function post(action, id) {
        const body = new URLSearchParams({action, id, nonce: hdrjpgAdmin.nonce});
        return fetch(hdrjpgAdmin.ajaxUrl, {method: 'POST', body, credentials: 'same-origin'})
            .then((response) => response.json());
    }

    function render(id, data) {
        document.querySelectorAll('.hdrjpg-field[data-id="' + id + '"]').forEach((field) => {
            field.innerHTML = data.html;
        });
    }

    function follow(id) {
        if (followed.has(id)) {
            return;
        }
        followed.add(id);
        const check = () => post('hdrjpg_status', id).then((response) => {
            if (response.success) {
                render(id, response.data);
                if (response.data.status === 'running') {
                    setTimeout(check, 4000);
                    return;
                }
            }
            followed.delete(id);
        }).catch(() => setTimeout(check, 8000));
        setTimeout(check, 4000);
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.hdrjpg-convert');
        if (!button) {
            return;
        }
        const id = button.closest('.hdrjpg-field').dataset.id;
        button.disabled = true;
        post('hdrjpg_convert', id).then((response) => {
            if (response.success) {
                render(id, response.data);
                if (response.data.status === 'running') {
                    follow(id);
                }
            } else {
                button.disabled = false;
                alert(response.data && response.data.message ? response.data.message : 'HDRJPG: the conversion could not start.');
            }
        }).catch(() => {
            button.disabled = false;
        });
    });

    // The details panel is drawn by the media modal after page load: follow running conversions as they appear
    new MutationObserver(() => {
        document.querySelectorAll('.hdrjpg-field .hdrjpg-running').forEach((running) => {
            follow(running.closest('.hdrjpg-field').dataset.id);
        });
    }).observe(document.body, {childList: true, subtree: true});
})();
