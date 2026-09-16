import type { Auth } from '@/types/auth';
import type Echo from 'laravel-echo';
import type Pusher from 'pusher-js';

interface SoketiConfig {
    key?: string | null;
    host?: string | null;
    port?: string | number | null;
    scheme?: string | null;
    cluster?: string | null;
}

declare global {
    var __SOKETI__: SoketiConfig | undefined;
    var Echo: Echo | undefined;
    var Pusher: typeof Pusher;
}

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        readonly VITE_PUSHER_APP_KEY?: string;
        readonly VITE_PUSHER_HOST?: string;
        readonly VITE_PUSHER_PORT?: string;
        readonly VITE_PUSHER_SCHEME?: string;
        readonly VITE_PUSHER_APP_CLUSTER?: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
