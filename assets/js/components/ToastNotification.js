export class ToastNotification {
    constructor(selector = '#toastRoot') {
        this.el = document.querySelector(selector);
        this.timer = null;
    }

    show(message, duration = 3000) {
        if (!this.el) return;
        this.el.textContent = message;
        this.el.classList.remove('hidden');
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.el.classList.add('hidden'), duration);
    }
}
