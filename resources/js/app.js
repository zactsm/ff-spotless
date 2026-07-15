import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { registerSW } from 'virtual:pwa-register';

const appName = import.meta.env.VITE_APP_NAME || 'FF Spotless';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const resolvePage = pages[`./Pages/${name}.vue`];

        if (!resolvePage) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return resolvePage();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});

if ('serviceWorker' in navigator) {
    registerSW({
        immediate: true,
        onRegisterError(error) {
            console.warn('Tidak dapat mendaftarkan aplikasi luar talian.', error);
        },
    });
}
