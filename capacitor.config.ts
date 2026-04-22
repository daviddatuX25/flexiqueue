import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'ph.edu.ispsc.flexiqueue',
  appName: 'FlexiQueue',
  webDir: 'public',
  server: {
    url: 'http://127.0.0.1:8000',
    cleartext: true,
    androidScheme: 'http',
  },
  android: {
    allowNavigation: ['127.0.0.1'],
  },
};

export default config;
