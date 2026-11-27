import {useEffect} from 'react';
import {applicationId, nativeBuildVersion} from 'expo-application';
import {Alert, AppState, Linking, Platform} from 'react-native';

const RELEASE_URL = 'https://github.com/baconene/bypassgrill/releases/download/android-pos';
const CHECK_INTERVAL_MS = 15 * 60 * 1000;

export function useAndroidRelease() {
  useEffect(() => {
    const installed = Number(nativeBuildVersion);
    if (Platform.OS !== 'android' || applicationId !== 'com.bypassgrill.pos' ||
        !Number.isSafeInteger(installed) || installed < 1 || __DEV__) return;

    let request: AbortController | undefined;
    let promptedVersion = installed;
    const isForeground = () => AppState.currentState === 'active';

    async function check() {
      if (request || !isForeground()) return;
      const controller = new AbortController();
      request = controller;
      const timeout = setTimeout(() => controller.abort(), 10000);

      try {
        const response = await fetch(`${RELEASE_URL}/update.json?check=${Date.now()}`, {
          signal: controller.signal,
        });
        if (!response.ok) return;

        const release = await response.json();
        if (controller.signal.aborted || !isForeground() ||
            !Number.isSafeInteger(release?.versionCode) || release.versionCode <= promptedVersion ||
            typeof release.version !== 'string' || !/^\d+\.\d+\.\d+$/.test(release.version)) return;

        promptedVersion = release.versionCode;
        Alert.alert(
          'Bypass Grill POS update available',
          `Version ${release.version} (build ${release.versionCode}) is ready to download.`,
          [
            {text: 'Later', style: 'cancel'},
            {
              text: 'Download APK',
              onPress: () => {
                Linking.openURL(`${RELEASE_URL}/bypass-grill-pos.apk`).catch(() => {
                  promptedVersion = installed;
                  Alert.alert('Download unavailable', 'Try again when you have an internet connection.');
                });
              },
            },
          ],
        );
      } catch {
        // A release check must never interrupt offline POS use.
      } finally {
        clearTimeout(timeout);
        if (request === controller) request = undefined;
      }
    }

    const subscription = AppState.addEventListener('change', state => {
      if (state === 'active') void check();
      else {
        request?.abort();
        request = undefined;
      }
    });
    const timer = setInterval(check, CHECK_INTERVAL_MS);
    void check();
    return () => {
      subscription.remove();
      clearInterval(timer);
      request?.abort();
    };
  }, []);
}
