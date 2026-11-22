export class ClipboardHelper {
    static async copyText(text, triggerButton = null, codeFallbackEl = null, toast = null) {
        let copied = false;

        if (window.isSecureContext && navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(text);
                copied = true;
            } catch {
                copied = false;
            }
        }

        if (!copied) {
            const el = document.createElement('textarea');
            el.value = text;
            el.readOnly = true;
            el.contentEditable = 'true';
            el.style.position = 'fixed';
            el.style.top = '0';
            el.style.left = '0';
            el.style.opacity = '0';
            el.style.fontSize = '16px';
            document.body.appendChild(el);

            try {
                el.focus();
                el.select();
                el.setSelectionRange(0, text.length);
                copied = document.execCommand('copy');
            } catch {
                copied = false;
            } finally {
                document.body.removeChild(el);
            }
        }

        if (copied) {
            if (triggerButton) {
                const origText = triggerButton.dataset.origLabel || triggerButton.textContent;
                triggerButton.dataset.origLabel = origText;
                triggerButton.textContent = '✓ Gekopieerd';
                triggerButton.classList.add('is-copied');
                clearTimeout(Number(triggerButton.dataset.copyTimer || 0));
                const timer = setTimeout(() => {
                    triggerButton.textContent = origText;
                    triggerButton.classList.remove('is-copied');
                }, 2000);
                triggerButton.dataset.copyTimer = String(timer);
            }
            if (toast) toast.show('Gekopieerd naar klembord!');
            return true;
        }

        if (codeFallbackEl) {
            const range = document.createRange();
            range.selectNodeContents(codeFallbackEl);
            const sel = window.getSelection();
            sel?.removeAllRanges();
            sel?.addRange(range);
        }
        if (toast) {
            toast.show('Kopiëren geblokkeerd — druk op Cmd+C / Kopieer handmatig.');
        }
        return false;
    }
}
