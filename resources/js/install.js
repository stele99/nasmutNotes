let deferredPrompt = null;
let installed = window.matchMedia('(display-mode: standalone)').matches
  || window.navigator.standalone === true;
const listeners = new Set();

function isIos() {
  return /iphone|ipad|ipod/i.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function notify() {
  const state = installState();
  for (const listener of listeners) {
    listener(state);
  }
}

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  deferredPrompt = event;
  notify();
});

window.addEventListener('appinstalled', () => {
  deferredPrompt = null;
  installed = true;
  notify();
});

export function installState() {
  return {
    canPrompt: deferredPrompt !== null,
    installed,
    showIosHint: isIos() && !installed,
  };
}

export function onInstallStateChange(listener) {
  listeners.add(listener);
  listener(installState());
  return () => listeners.delete(listener);
}

export async function promptInstall() {
  if (!deferredPrompt) {
    return false;
  }
  const prompt = deferredPrompt;
  deferredPrompt = null;
  await prompt.prompt();
  const choice = await prompt.userChoice;
  if (choice.outcome === 'accepted') {
    installed = true;
  }
  notify();
  return choice.outcome === 'accepted';
}
