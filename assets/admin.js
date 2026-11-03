/* HDRJPG: starts conversions from a photo's details in the Media Library, follows them until they finish, and shows
   converted photos in HDR in their preview, with the same HDRJPG embed as on the site. */
(function () {
    const followed = new Set();
    // The embed markup each preview shows
    const shown = new WeakMap();

    function post(action, id) {
        const body = new URLSearchParams({action, id, nonce: hdrjpgAdmin.nonce});
        return fetch(hdrjpgAdmin.ajaxUrl, {method: 'POST', body, credentials: 'same-origin'})
            .then((response) => response.json());
    }

    function render(id, data) {
        document.querySelectorAll('.hdrjpg-field[data-id="' + id + '"]').forEach((field) => {
            field.innerHTML = data.html;
            field.dataset.hdrHtml = data.hdrHtml;
            showHdr(field);
        });
    }

    // The preview next to the field, in the media modal or on the attachment's edit page: WordPress's image, or the
    // embed that already replaced it
    function preview(field) {
        const details = field.closest('.attachment-details');
        return details
            ? details.querySelector('.hdrjpg[data-hdrjpg-preview], img.details-image')
            : document.querySelector('#thumbnail-head-' + field.dataset.id + ' :is(.hdrjpg[data-hdrjpg-preview], img)');
    }

    function showHdr(field) {
        const current = preview(field);
        const html = field.dataset.hdrHtml;
        if (!current || !html || shown.get(current) === html) {
            return;
        }
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const embed = template.content.firstElementChild;
        const img = embed.querySelector('img');
        // The preview's own look: WordPress styles it by its class, and sizes it with CSS rather than width and height
        const original = current.matches('img') ? current : current.querySelector('img, video');
        embed.dataset.hdrjpgPreview = '';
        embed.dataset.previewClass = current.dataset.previewClass ?? original.getAttribute('class') ?? '';
        embed.dataset.previewStyle = current.dataset.previewStyle ?? original.getAttribute('style') ?? '';
        img.setAttribute('class', embed.dataset.previewClass);
        img.setAttribute('style', [embed.dataset.previewStyle, img.getAttribute('style')].filter(Boolean).join('; '));
        img.removeAttribute('width');
        img.removeAttribute('height');
        img.draggable = false;
        shown.set(embed, html);
        current.replaceWith(embed);
        // Firefox and Safari on a Mac show the MP4 instead, as on the site
        hdrjpg.apply(embed.parentElement);
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

    // The details panel is drawn by the media modal after page load: follow running conversions and show converted
    // photos in HDR as they appear
    const scan = () => {
        document.querySelectorAll('.hdrjpg-field .hdrjpg-running').forEach((running) => {
            follow(running.closest('.hdrjpg-field').dataset.id);
        });
        document.querySelectorAll('.hdrjpg-field[data-hdr-html]').forEach(showHdr);
    };
    new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
    scan();
})();
