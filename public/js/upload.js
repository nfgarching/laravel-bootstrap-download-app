// Upload-Formulare: Schaltfläche sperren und Hinweis zeigen, solange übertragen wird.
// Externe Datei, weil die CSP keine Inline-Skripte erlaubt.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-upload-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('[data-upload-button]');
            if (button) button.disabled = true;
            form.querySelector('[data-upload-spinner]')?.classList.remove('d-none');
            form.querySelector('[data-upload-wait]')?.classList.remove('d-none');
        });
    });
});
