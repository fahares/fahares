// Browser notifications (backlog item 25). Permission is asked only from a button the user presses,
// never on page load: a browser that was refused once cannot ask again.
const DISMISS_PREFIX = 'fahares.push.dismissed.';

function base64ToBytes(base64) {
    const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
}

function request(method, body) {
    return fetch('/push-subscriptions', {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(body),
    }).then((r) => {
        if (!r.ok) throw new Error(r.status);
        return r.json();
    });
}

export default (context = 'settings') => ({
    context,
    supported: false,
    // iPhone and iPad allow push only to a site added to the home screen (iOS 16.4+)
    needsHomeScreen: false,
    permission: 'default',
    subscribed: false,
    dismissed: false,
    busy: false,
    message: '',

    async init() {
        const key = document.querySelector('meta[name="vapid-public-key"]')?.content;
        const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        this.needsHomeScreen = ios && !standalone;
        this.supported = !!key && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        try {
            this.dismissed = localStorage.getItem(DISMISS_PREFIX + this.context) === '1';
        } catch (e) {
            this.dismissed = false;
        }
        if (!this.supported) return;
        this.permission = Notification.permission;
        const registration = await navigator.serviceWorker.getRegistration('/');
        this.subscribed = !!(registration && await registration.pushManager.getSubscription());
    },

    // A soft invite shows only where it can still help, and never again once closed
    get invite() {
        return this.supported && !this.subscribed && !this.dismissed && this.permission !== 'denied';
    },

    async enable() {
        this.busy = true;
        this.message = '';
        try {
            this.permission = await Notification.requestPermission();
            if (this.permission !== 'granted') {
                this.message = 'مرورگر اجازه‌ی اعلان نداد. اگر خواستید، از تنظیمات مرورگر برای این سایت اجازه دهید.';
                return;
            }
            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            await navigator.serviceWorker.ready;
            const key = document.querySelector('meta[name="vapid-public-key"]').content;
            const subscription = await registration.pushManager.getSubscription()
                || await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64ToBytes(key) });
            const json = subscription.toJSON();
            const encoding = (PushManager.supportedContentEncodings || ['aesgcm'])[0] === 'aes128gcm' ? 'aes128gcm' : 'aesgcm';
            this.message = (await request('POST', { endpoint: json.endpoint, keys: json.keys, contentEncoding: encoding })).message;
            this.subscribed = true;
            // The settings table shows the browser column switched on only after a reload
            if (this.context === 'settings') window.location.reload();
        } catch (e) {
            this.message = 'فعال‌سازی اعلان مرورگر انجام نشد. دوباره تلاش کنید.';
        } finally {
            this.busy = false;
        }
    },

    async disable() {
        this.busy = true;
        try {
            const registration = await navigator.serviceWorker.getRegistration('/');
            const subscription = registration && await registration.pushManager.getSubscription();
            if (subscription) {
                await request('DELETE', { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }
            this.subscribed = false;
            this.message = 'اعلان مرورگر در این مرورگر خاموش شد.';
        } catch (e) {
            this.message = 'خاموش‌کردن اعلان مرورگر انجام نشد.';
        } finally {
            this.busy = false;
        }
    },

    dismiss() {
        this.dismissed = true;
        try {
            localStorage.setItem(DISMISS_PREFIX + this.context, '1');
        } catch (e) {
            // Without storage the invite simply returns on the next visit
        }
    },
});
