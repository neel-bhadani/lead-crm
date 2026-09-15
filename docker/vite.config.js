import { mergeConfig } from 'vite';
import baseConfig from '../vite.config.js';

export default mergeConfig(baseConfig, {
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5174',
        cors: { origin: 'http://localhost:8080' },
        hmr: { host: 'localhost', clientPort: 5174 },
    },
});
