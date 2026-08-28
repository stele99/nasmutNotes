import assert from 'node:assert/strict';
import test from 'node:test';

/**
 * Automatisierte Prüfungen für den Recorder-Controller und den gemeinsamen
 * Voice-Zustand (voiceRecorderMixin). Die Browser-Umgebung wird durch einen
 * kleinen MediaRecorder- und MediaStream-Fake sowie kontrollierte Timer
 * ersetzt; es kommt keine Testbibliothek dazu.
 */

class FakeMediaStreamTrack {
  constructor() {
    this.stopped = false;
  }

  stop() {
    this.stopped = true;
  }
}

class FakeMediaStream {
  constructor() {
    this.tracks = [new FakeMediaStreamTrack(), new FakeMediaStreamTrack()];
  }

  getTracks() {
    return this.tracks;
  }
}

class FakeMediaRecorder {
  static instances = [];
  static chunkSize = 4096;

  static isTypeSupported(type) {
    return type === 'audio/webm;codecs=opus' || type === 'audio/webm';
  }

  constructor(stream, options) {
    this.stream = stream;
    this.mimeType = options?.mimeType || 'audio/webm';
    this.state = 'inactive';
    this.pauseCalls = 0;
    this.resumeCalls = 0;
    this.listeners = {};
    FakeMediaRecorder.instances.push(this);
  }

  addEventListener(name, listener) {
    (this.listeners[name] ||= []).push(listener);
  }

  emit(name, event) {
    for (const listener of this.listeners[name] || []) {
      listener(event);
    }
  }

  start() {
    this.state = 'recording';
  }

  pause() {
    if (this.state !== 'recording') {
      throw new DOMException('Recorder ist nicht aktiv.', 'InvalidStateError');
    }
    this.pauseCalls += 1;
    this.state = 'paused';
  }

  resume() {
    if (this.state !== 'paused') {
      throw new DOMException('Recorder ist nicht pausiert.', 'InvalidStateError');
    }
    this.resumeCalls += 1;
    this.state = 'recording';
  }

  stop() {
    if (this.state === 'inactive') {
      throw new DOMException('Recorder ist bereits beendet.', 'InvalidStateError');
    }
    this.state = 'inactive';
    const chunk = new Blob(['x'.repeat(FakeMediaRecorder.chunkSize)], { type: this.mimeType });
    this.emit('dataavailable', { data: chunk });
    this.emit('stop');
  }
}

let getUserMediaCalls = 0;
let getUserMediaImpl = async () => new FakeMediaStream();
let lastStream = null;

function installGetUserMedia(impl) {
  getUserMediaCalls = 0;
  getUserMediaImpl = impl || (async () => {
    lastStream = new FakeMediaStream();
    return lastStream;
  });
  globalThis.navigator.mediaDevices.getUserMedia = () => {
    getUserMediaCalls += 1;
    return getUserMediaImpl();
  };
}

globalThis.window = globalThis;
globalThis.MediaRecorder = FakeMediaRecorder;
globalThis.navigator = {
  onLine: true,
  mediaDevices: {},
};

installGetUserMedia();

const {
  startVoiceRecording,
  voiceRecorderMixin,
  voiceTemplateMixin,
  voiceFormData,
} = await import('../../resources/js/voice.js');

function makeComponent(handlers = {}) {
  return {
    ...voiceRecorderMixin(),
    ...voiceTemplateMixin(),
    $nextTick(fn) {
      fn();
    },
    $dispatch() {},
    handleVoiceRecording: handlers.handleVoiceRecording
      || (async () => {}),
  };
}

/** Räumt verbleibende Mikrotasks ab, damit schwebende Startanfragen enden. */
function flush() {
  return new Promise((resolve) => {
    setTimeout(resolve, 0);
  });
}

function lastRecorder() {
  return FakeMediaRecorder.instances[FakeMediaRecorder.instances.length - 1];
}

/** Frischer Recorder-, Stream- und Aufrufstand für einen Test. */
function resetRecordingState() {
  FakeMediaRecorder.instances = [];
  FakeMediaRecorder.chunkSize = 4096;
  installGetUserMedia();
}

test('wählt einen unterstützten MIME-Typ und Dateinamen', async () => {
  resetRecordingState();
  const controller = await startVoiceRecording();
  const recording = await controller.stop();
  assert.equal(recording.filename, 'aufnahme.webm');
  assert.equal(recording.blob.type, 'audio/webm;codecs=opus');
});

test('startet genau einen Recorder und einen Stream', async () => {
  resetRecordingState();
  const controller = await startVoiceRecording();
  assert.equal(getUserMediaCalls, 1);
  assert.equal(FakeMediaRecorder.instances.length, 1);
  await controller.stop();
  assert.equal(FakeMediaRecorder.instances.length, 1);
});

test('stop() funktioniert aus dem laufenden Zustand und erzeugt genau einen Blob', async (t) => {
  t.mock.timers.enable({ apis: ['Date', 'setInterval'] });
  installGetUserMedia();
  const controller = await startVoiceRecording();
  t.mock.timers.tick(2000);
  const recording = await controller.stop();
  assert.equal(recording.seconds, 2);
  assert.ok(recording.blob.size > 0);
  assert.ok(lastStream.getTracks().every((track) => track.stopped));
});

test('stop() funktioniert aus dem pausierten Zustand', async () => {
  const controller = await startVoiceRecording();
  await controller.pause();
  const recording = await controller.stop();
  assert.ok(recording.blob.size > 0);
  assert.ok(lastStream.getTracks().every((track) => track.stopped));
});

test('pause() ruft die native Pause genau einmal auf', async () => {
  const controller = await startVoiceRecording();
  const recorder = lastRecorder();
  await controller.pause();
  assert.equal(recorder.pauseCalls, 1);
  assert.equal(recorder.state, 'paused');
  await controller.pause();
  assert.equal(recorder.pauseCalls, 1);
  await controller.cancel();
});

test('resume() ruft das native Resume genau einmal auf', async () => {
  const controller = await startVoiceRecording();
  const recorder = lastRecorder();
  await controller.pause();
  await controller.resume();
  await controller.resume();
  assert.equal(recorder.resumeCalls, 1);
  assert.equal(recorder.state, 'recording');
  await controller.cancel();
});

test('cancel() verwirft Chunks und beendet alle Tracks', async () => {
  const controller = await startVoiceRecording();
  controller.cancel();
  assert.ok(lastStream.getTracks().every((track) => track.stopped));
  const recording = await controller.stop();
  assert.equal(recording.blob.size, 0);
});

test('aktive Sekunden steigen während der Aufnahme', async (t) => {
  t.mock.timers.enable({ apis: ['Date', 'setInterval'] });
  let seconds = 0;
  const controller = await startVoiceRecording({
    onTick: (value) => {
      seconds = value;
    },
  });
  t.mock.timers.tick(1500);
  assert.equal(seconds, 1);
  t.mock.timers.tick(1500);
  assert.equal(seconds, 3);
  controller.cancel();
});

test('Sekunden bleiben während der Pause unverändert und laufen danach weiter', async (t) => {
  t.mock.timers.enable({ apis: ['Date', 'setInterval'] });
  let seconds = 0;
  const controller = await startVoiceRecording({
    onTick: (value) => {
      seconds = value;
    },
  });
  t.mock.timers.tick(2000);
  await controller.pause();
  t.mock.timers.tick(30_000);
  assert.equal(seconds, 2);
  await controller.resume();
  t.mock.timers.tick(1000);
  assert.equal(seconds, 3);
  controller.cancel();
});

test('das Zeitlimit wird während einer Pause nicht erreicht', async (t) => {
  t.mock.timers.enable({ apis: ['Date', 'setInterval'] });
  let limited = 0;
  const controller = await startVoiceRecording({
    maxSeconds: 5,
    onLimit: () => {
      limited += 1;
    },
  });
  t.mock.timers.tick(2000);
  await controller.pause();
  t.mock.timers.tick(120_000);
  assert.equal(limited, 0);
  await controller.resume();
  // Ein Tick vor dem Limit, dann genau einer darüber - so feuert onLimit
  // genau einmal, auch wenn die Mock-Timer clearInterval im Callback ignorieren.
  t.mock.timers.tick(2900);
  assert.equal(limited, 0);
  t.mock.timers.tick(250);
  assert.equal(limited, 1);
  controller.cancel();
});

test('die gemeldete Dauer enthält keine Pausenzeit', async (t) => {
  t.mock.timers.enable({ apis: ['Date', 'setInterval'] });
  const controller = await startVoiceRecording();
  t.mock.timers.tick(3000);
  await controller.pause();
  t.mock.timers.tick(20_000);
  const recording = await controller.stop();
  assert.equal(recording.seconds, 3);

  const body = voiceFormData(recording);
  assert.equal(body.get('seconds'), '3');
});

test('startVoice wechselt sofort auf starting und danach auf recording', async () => {
  resetRecordingState();
  const component = makeComponent();
  try {
    const starting = component.startVoice();
    assert.equal(component.voiceStatus, 'starting');
    await starting;
    assert.equal(component.voiceStatus, 'recording');
  } finally {
    component.cancelVoice();
  }
});

test('ein Doppelklick startet keine zweite Mikrofonanfrage', async () => {
  let resolveMicrophone;
  installGetUserMedia(() => new Promise((resolve) => {
    resolveMicrophone = resolve;
  }));
  const component = makeComponent();
  try {
    const first = component.startVoice();
    await component.startVoice();
    assert.equal(getUserMediaCalls, 1);
    resolveMicrophone(new FakeMediaStream());
    await first;
    assert.equal(component.voiceStatus, 'recording');
  } finally {
    component.cancelVoice();
  }
});

test('Mikrofonfehler wechseln auf idle und setzen einen verständlichen Fehler', async () => {
  installGetUserMedia(async () => {
    throw Object.assign(new Error('denied'), { name: 'NotAllowedError' });
  });
  const component = makeComponent();
  await component.startVoice();
  assert.equal(component.voiceStatus, 'idle');
  assert.match(component.voiceError, /abgelehnt/);
});

test('pauseVoice wechselt erst nach erfolgreicher Pause auf paused', async () => {
  resetRecordingState();
  const component = makeComponent();
  try {
    await component.startVoice();
    await component.pauseVoice();
    assert.equal(component.voiceStatus, 'paused');
    assert.equal(lastRecorder().pauseCalls, 1);
  } finally {
    component.cancelVoice();
  }
});

test('resumeVoice wechselt erst nach erfolgreichem Resume auf recording', async () => {
  resetRecordingState();
  const component = makeComponent();
  try {
    await component.startVoice();
    await component.pauseVoice();
    await component.resumeVoice();
    assert.equal(component.voiceStatus, 'recording');
    assert.equal(lastRecorder().resumeCalls, 1);
  } finally {
    component.cancelVoice();
  }
});

test('stopVoice wechselt auf processing und hält den Busy-Zustand bis zur Auflösung offen', async () => {
  installGetUserMedia();
  let release;
  const component = makeComponent({
    handleVoiceRecording: () => new Promise((resolve) => {
      release = resolve;
    }),
  });
  await component.startVoice();
  const stopping = component.stopVoice();
  await flush();
  assert.equal(component.voiceStatus, 'processing');
  assert.equal(component.isVoiceBusy(), true);
  release();
  await stopping;
  assert.equal(component.voiceStatus, 'idle');
  assert.equal(component.isVoiceBusy(), false);
});

test('stopVoice funktioniert aus dem pausierten Zustand', async () => {
  installGetUserMedia();
  const component = makeComponent();
  await component.startVoice();
  await component.pauseVoice();
  await component.stopVoice();
  assert.equal(component.voiceStatus, 'idle');
  assert.equal(component.voiceError, '');
});

test('ein Fehler aus handleVoiceRecording setzt voiceError und lässt den Dialog offen', async () => {
  installGetUserMedia();
  const component = makeComponent({
    handleVoiceRecording: async () => {
      throw new Error('Speichern fehlgeschlagen.');
    },
  });
  await component.startVoice();
  await component.stopVoice();
  assert.equal(component.voiceStatus, 'idle');
  assert.equal(component.voiceError, 'Speichern fehlgeschlagen.');
  assert.equal(component.isVoiceDialogOpen(), true);
});

test('Verwerfen aus Aufnahme und Pause setzt Zustand, Zeit und Meldungen zurück', async () => {
  resetRecordingState();
  const component = makeComponent();
  try {
    await component.startVoice();
    component.voiceNotice = 'Hinweis';
    component.cancelVoice();
    assert.equal(component.voiceStatus, 'idle');
    assert.equal(component.voiceSeconds, 0);
    assert.equal(component.voiceError, '');
    assert.equal(component.voiceNotice, '');
    assert.ok(lastStream.getTracks().every((track) => track.stopped));

    await component.startVoice();
    await component.pauseVoice();
    component.cancelVoice();
    assert.equal(component.voiceStatus, 'idle');
    assert.ok(lastStream.getTracks().every((track) => track.stopped));
  } finally {
    component.cancelVoice();
  }
});

test('ein zu kurzer Blob verwendet die bestehende Fehlermeldung', async () => {
  installGetUserMedia();
  FakeMediaRecorder.chunkSize = 10;
  const component = makeComponent();
  await component.startVoice();
  await component.stopVoice();
  FakeMediaRecorder.chunkSize = 4096;
  assert.equal(component.voiceStatus, 'idle');
  assert.equal(component.voiceError, 'Die Aufnahme war zu kurz.');
});

test('eine verspätete Mikrofonfreigabe nach dem Verwerfen schließt ihren Stream', async () => {
  let resolveMicrophone;
  installGetUserMedia(() => new Promise((resolve) => {
    resolveMicrophone = resolve;
  }));
  const component = makeComponent();
  const starting = component.startVoice();
  assert.equal(component.voiceStatus, 'starting');
  component.cancelVoice();
  const lateStream = new FakeMediaStream();
  resolveMicrophone(lateStream);
  await starting;
  assert.ok(lateStream.getTracks().every((track) => track.stopped));
  assert.equal(component.voiceStatus, 'idle');
});

test('die Zeit friert im Mixin während der Pause ein und läuft danach weiter', async (t) => {
  t.mock.timers.enable({ apis: ['Date', 'setInterval'] });
  resetRecordingState();
  const component = makeComponent();
  try {
    await component.startVoice();
    t.mock.timers.tick(2000);
    assert.equal(component.voiceSeconds, 2);
    await component.pauseVoice();
    t.mock.timers.tick(30_000);
    assert.equal(component.voiceSeconds, 2);
    await component.resumeVoice();
    t.mock.timers.tick(1000);
    assert.equal(component.voiceSeconds, 3);
  } finally {
    component.cancelVoice();
  }
});

test('das automatische Zeitlimit beendet die Aufnahme im Mixin', async (t) => {
  t.mock.timers.enable({ apis: ['Date', 'setInterval'] });
  installGetUserMedia();
  globalThis.window.__VOICE__ = { maxSeconds: 5 };
  let release;
  const component = makeComponent({
    handleVoiceRecording: () => new Promise((resolve) => {
      release = resolve;
    }),
  });
  await component.startVoice();
  t.mock.timers.tick(6000);
  await flush();
  assert.equal(component.voiceNotice, 'Die Höchstdauer war erreicht, die Aufnahme wurde beendet.');
  assert.equal(component.voiceStatus, 'processing');
  release();
  await flush();
  assert.equal(component.voiceStatus, 'idle');
  delete globalThis.window.__VOICE__;
});

test('Backdrop und Escape dürfen Aufnahme und Pause nicht schließen', async () => {
  resetRecordingState();
  const component = makeComponent();
  try {
    await component.startVoice();
    component.closeVoiceDialog();
    assert.equal(component.voiceStatus, 'recording');
    await component.pauseVoice();
    component.closeVoiceDialog();
    assert.equal(component.voiceStatus, 'paused');
  } finally {
    component.cancelVoice();
  }
});

test('bei einem Fehler schließt der Dialog über dismissVoiceError', async () => {
  resetRecordingState();
  const component = makeComponent();
  await component.startVoice();
  await component.stopVoice();
  component.voiceError = 'Fehler';
  assert.equal(component.isVoiceDialogOpen(), true);
  component.closeVoiceDialog();
  assert.equal(component.voiceError, '');
  assert.equal(component.isVoiceDialogOpen(), false);
});

test('die Vorlagenauswahl lässt sich über Escape schließen', () => {
  const component = makeComponent();
  component.voiceTemplatePickerOpen = true;
  assert.equal(component.isVoiceDialogOpen(), true);
  component.closeVoiceDialog();
  assert.equal(component.voiceTemplatePickerOpen, false);
  assert.equal(component.isVoiceDialogOpen(), false);
});

test('die Vorlagenbestätigung wechselt im selben Zug auf starting', async () => {
  resetRecordingState();
  const component = makeComponent();
  try {
    component.voiceTemplates = [{ id: 7, name: 'Standard', scope: 'global' }];
    component.voiceTemplateId = 7;
    component.voiceTemplatePickerOpen = true;
    component.confirmVoiceTemplate();
    assert.equal(component.voiceTemplatePickerOpen, false);
    assert.equal(component.voiceStatus, 'starting');
    await flush();
  } finally {
    component.cancelVoice();
  }
});
