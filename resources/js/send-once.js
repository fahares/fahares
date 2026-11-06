// A message form (form[data-send-once]) is sent once per click (backlog item 115): after the first submit its
// buttons are disabled and say «در حال فرستادن…», and a second submit is dropped. The server's client_token still
// settles a request the browser itself repeats; this only spares the double click.
export default function sendOnce() {
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-send-once') || event.defaultPrevented) {
            return;
        }
        if (form.dataset.sending === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.sending = '1';
        // after this tick, so the submit itself still goes out
        setTimeout(() => {
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
                button.dataset.label = button.textContent;
                button.textContent = 'در حال فرستادن…';
                button.disabled = true;
            });
        });
    });

    // A page restored from the back/forward cache gets its buttons back; its token is the old one, so a send of it again is the same send
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }
        document.querySelectorAll('form[data-send-once][data-sending="1"]').forEach((form) => {
            delete form.dataset.sending;
            form.querySelectorAll('button[data-label]').forEach((button) => {
                button.textContent = button.dataset.label;
                button.disabled = false;
            });
        });
    });
}
