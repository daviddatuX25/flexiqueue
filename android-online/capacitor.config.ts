import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.flexiqueue.online',
  appName: 'FlexiQueue',
  webDir: 'www',
  server: {
    url: 'https://flexiqueue.click',
    cleartext: true,
    androidScheme: 'https',
  },
  android: {
    allowMixedContent: false,
  },
};

export default config;