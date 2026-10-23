import { createApp } from 'vue';
import GuestMenu from './pages/guest/Menu.vue';

const root = document.getElementById('guest-app');
if (root)
    createApp(GuestMenu, {
        initiallyInvalid: root.dataset.invalid === 'true',
    }).mount(root);
