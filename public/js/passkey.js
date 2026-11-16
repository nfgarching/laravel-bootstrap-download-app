// Passkeys (WebAuthn) für Einrichtung und Anmeldung.
// Externe Datei statt Inline-Skript, weil die CSP nur eigene Skripte erlaubt.
//
// Schaltflächen:
//   <button data-passkey="register" data-options="URL" data-submit="URL" data-name="#feld">
//   <button data-passkey="login"    data-options="URL" data-submit="URL">
// Fehler erscheinen im Element [data-passkey-error] innerhalb desselben [data-passkey-box].
//
// Der Server (lbuchs/webauthn) kodiert Binärwerte als "=?BINARY?B?…?=";
// die Antworten gehen Base64-kodiert zurück.
(() => {
    const PREFIX = '=?BINARY?B?';
    const SUFFIX = '?=';

    const toBuffers = (obj) => {
        if (obj === null || typeof obj !== 'object') return;
        for (const key of Object.keys(obj)) {
            const value = obj[key];
            if (typeof value === 'string' && value.startsWith(PREFIX) && value.endsWith(SUFFIX)) {
                const binary = atob(value.slice(PREFIX.length, value.length - SUFFIX.length));
                const bytes = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                obj[key] = bytes.buffer;
            } else {
                toBuffers(value);
            }
        }
    };

    const toBase64 = (buffer) => {
        if (!buffer) return null;
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary);
    };

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const post = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body ?? {}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Die Anfrage ist fehlgeschlagen.');
        return data;
    };

    const showError = (button, message) => {
        const box = button.closest('[data-passkey-box]') ?? document;
        const target = box.querySelector('[data-passkey-error]');
        if (target) {
            target.textContent = message;
            target.classList.remove('d-none');
        }
    };

    const busy = (button, on) => {
        button.disabled = on;
        button.querySelector('[data-passkey-spinner]')?.classList.toggle('d-none', !on);
    };

    const register = async (button) => {
        const options = await post(button.dataset.options);
        toBuffers(options);
        const credential = await navigator.credentials.create(options);
        const nameInput = button.dataset.name ? document.querySelector(button.dataset.name) : null;
        return post(button.dataset.submit, {
            clientDataJSON: toBase64(credential.response.clientDataJSON),
            attestationObject: toBase64(credential.response.attestationObject),
            name: nameInput ? nameInput.value : null,
        });
    };

    const login = async (button) => {
        const options = await post(button.dataset.options);
        toBuffers(options);
        const credential = await navigator.credentials.get(options);
        return post(button.dataset.submit, {
            id: toBase64(credential.rawId),
            clientDataJSON: toBase64(credential.response.clientDataJSON),
            authenticatorData: toBase64(credential.response.authenticatorData),
            signature: toBase64(credential.response.signature),
        });
    };

    const messageFor = (error) => {
        if (error && error.name === 'NotAllowedError') return 'Der Vorgang wurde abgebrochen oder ist abgelaufen.';
        if (error && error.name === 'InvalidStateError') return 'Dieses Gerät ist bereits als Passkey eingerichtet.';
        if (error && error.name === 'SecurityError') return 'Passkeys funktionieren nur über die gesicherte Adresse des Portals.';
        return (error && error.message) || 'Der Vorgang ist fehlgeschlagen.';
    };

    document.addEventListener('DOMContentLoaded', () => {
        const buttons = document.querySelectorAll('[data-passkey]');
        const supported = !!(window.PublicKeyCredential && navigator.credentials);

        buttons.forEach((button) => {
            if (!supported) {
                button.disabled = true;
                showError(button, 'Dieser Browser unterstützt keine Passkeys. Bitte verwenden Sie einen aktuellen Browser oder Passwort und Authenticator-App.');
                return;
            }

            button.addEventListener('click', async () => {
                busy(button, true);
                try {
                    const result = button.dataset.passkey === 'login' ? await login(button) : await register(button);
                    window.location.assign(result.redirect || '/');
                } catch (error) {
                    showError(button, messageFor(error));
                    busy(button, false);
                }
            });
        });
    });
})();
