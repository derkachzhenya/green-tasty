import type { Environment } from 'vite-plus/test/environments';

// Compile Vue templates for interactions while retaining a dependency-free Node host.
export default {
    name: 'vue-client',
    viteEnvironment: 'client',
    setup() {
        return { teardown() {} };
    },
} satisfies Environment;
