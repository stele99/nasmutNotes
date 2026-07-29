/**
 * Aufnahme über MediaRecorder für die Sprachnotizen (FR-VOICE-01). Die Datei
 * kapselt nur Mikrofon und Zeitmessung; hochgeladen und ausgewertet wird sie
 * von den Alpine-Komponenten (pageList, noteEditorPage).
 */

// Reihenfolge nach Eignung: Opus in WebM liefert bei Sprache die kleinsten
// Dateien. Safari kann nur MP4, deshalb die Rückfallkette.
const MIME_CANDIDATES = [
  'audio/webm;codecs=opus',
  'audio/webm',
  'audio/mp4',
  'audio/ogg;codecs=opus',
  'audio/ogg',
];

const EXTENSIONS = [
  ['audio/webm', 'webm'],
  ['audio/ogg', 'ogg'],
  ['audio/mp4', 'mp4'],
  ['audio/mpeg', 'mp3'],
  ['audio/wav', 'wav'],
];

/** Aufnahmen unter dieser Größe enthalten faktisch nichts Verwertbares. */
const MIN_BLOB_BYTES = 2000;

export function isVoiceRecordingSupported() {
  return typeof window !== 'undefined'
    && typeof window.MediaRecorder === 'function'
    && Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

function pickMimeType() {
  if (typeof MediaRecorder.isTypeSupported !== 'function') {
    return '';
  }

  return MIME_CANDIDATES.find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

function filenameFor(mimeType) {
  const base = String(mimeType || '').split(';')[0].trim().toLowerCase();
  const match = EXTENSIONS.find(([type]) => type === base);

  return `aufnahme.${match ? match[1] : 'webm'}`;
}

/**
 * Übersetzt die Fehler von getUserMedia in etwas, das in der Oberfläche stehen
 * kann. Ohne HTTPS gibt es navigator.mediaDevices gar nicht erst.
 */
export function describeRecorderError(error) {
  if (!isVoiceRecordingSupported()) {
    return 'Dieser Browser kann keine Aufnahmen erstellen. Sprachnotizen brauchen eine HTTPS-Verbindung.';
  }
  const name = error?.name || '';
  if (name === 'NotAllowedError' || name === 'SecurityError') {
    return 'Der Zugriff auf das Mikrofon wurde abgelehnt. Bitte in den Browsereinstellungen erlauben.';
  }
  if (name === 'NotFoundError' || name === 'OverconstrainedError') {
    return 'Es wurde kein Mikrofon gefunden.';
  }
  if (name === 'NotReadableError') {
    return 'Das Mikrofon wird bereits von einer anderen Anwendung benutzt.';
  }

  return error?.message || 'Die Aufnahme konnte nicht gestartet werden.';
}

/**
 * Startet eine Aufnahme und liefert die Steuerung dafür zurück.
 *
 * @param {{ maxSeconds?: number, onTick?: (seconds: number) => void, onLimit?: () => void }} options
 * @returns {Promise<{ stop: () => Promise<{ blob: Blob, filename: string, seconds: number }>, cancel: () => void }>}
 */
export async function startVoiceRecording({ maxSeconds = 300, onTick, onLimit } = {}) {
  if (!isVoiceRecordingSupported()) {
    throw new Error('Dieser Browser kann keine Aufnahmen erstellen.');
  }

  const stream = await navigator.mediaDevices.getUserMedia({
    audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
  });

  const mimeType = pickMimeType();
  const recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
  const chunks = [];
  recorder.addEventListener('dataavailable', (event) => {
    if (event.data && event.data.size > 0) {
      chunks.push(event.data);
    }
  });

  const stopped = new Promise((resolve) => {
    recorder.addEventListener('stop', resolve, { once: true });
  });

  const startedAt = Date.now();
  let released = false;
  // Der Zeitgeber bedient die Anzeige und beendet die Aufnahme selbsttätig am
  // Limit - sonst liefe sie bis zur Dateigrenze des Dienstes weiter.
  const timer = window.setInterval(() => {
    const seconds = Math.floor((Date.now() - startedAt) / 1000);
    if (typeof onTick === 'function') {
      onTick(seconds);
    }
    if (seconds >= maxSeconds && typeof onLimit === 'function') {
      window.clearInterval(timer);
      onLimit();
    }
  }, 250);

  function release() {
    if (released) {
      return;
    }
    released = true;
    window.clearInterval(timer);
    stream.getTracks().forEach((track) => track.stop());
  }

  recorder.start();

  return {
    async stop() {
      if (recorder.state !== 'inactive') {
        recorder.stop();
        await stopped;
      }
      release();

      const type = recorder.mimeType || mimeType || 'audio/webm';
      const blob = new Blob(chunks, { type });

      return {
        blob,
        filename: filenameFor(type),
        seconds: Math.round((Date.now() - startedAt) / 1000),
      };
    },

    cancel() {
      try {
        if (recorder.state !== 'inactive') {
          recorder.stop();
        }
      } catch {
        /* Der Zustand ist danach ohnehin egal. */
      }
      chunks.length = 0;
      release();
    },
  };
}

/**
 * Gemeinsamer Zustand der Aufnahmeknöpfe. Wird in die Alpine-Komponenten
 * gemischt, die ihn brauchen; jede von ihnen liefert `handleVoiceRecording`
 * und entscheidet damit, was mit der fertigen Aufnahme geschieht.
 */
export function voiceRecorderMixin() {
  // Bewusst außerhalb des Alpine-Zustands: MediaRecorder und MediaStream
  // vertragen keine reaktive Hülle.
  let controller = null;

  return {
    voiceSupported: isVoiceRecordingSupported(),
    voiceStatus: 'idle', // idle | recording | processing
    voiceSeconds: 0,
    voiceError: '',
    voiceNotice: '',
    voiceMaxSeconds: Number(window.__VOICE__?.maxSeconds || 300),

    async startVoice() {
      if (this.voiceStatus !== 'idle') {
        return;
      }
      if (!navigator.onLine) {
        this.voiceError = 'Sprachnotizen brauchen eine Internetverbindung.';
        return;
      }

      this.voiceError = '';
      this.voiceNotice = '';
      this.voiceSeconds = 0;
      try {
        controller = await startVoiceRecording({
          maxSeconds: this.voiceMaxSeconds,
          onTick: (seconds) => {
            this.voiceSeconds = seconds;
          },
          onLimit: () => {
            this.voiceNotice = 'Die Höchstdauer war erreicht, die Aufnahme wurde beendet.';
            void this.stopVoice();
          },
        });
        this.voiceStatus = 'recording';
      } catch (error) {
        this.voiceError = describeRecorderError(error);
      }
    },

    async stopVoice() {
      if (!controller || this.voiceStatus !== 'recording') {
        return;
      }

      this.voiceStatus = 'processing';
      let recording = null;
      try {
        recording = await controller.stop();
      } finally {
        controller = null;
      }

      if (isTooShort(recording)) {
        this.voiceStatus = 'idle';
        this.voiceError = 'Die Aufnahme war zu kurz.';
        return;
      }

      try {
        await this.handleVoiceRecording(recording);
      } catch (error) {
        this.voiceError = error?.message || 'Die Aufnahme konnte nicht verarbeitet werden.';
      } finally {
        this.voiceStatus = 'idle';
        this.voiceSeconds = 0;
      }
    },

    cancelVoice() {
      controller?.cancel();
      controller = null;
      this.voiceStatus = 'idle';
      this.voiceSeconds = 0;
      this.voiceError = '';
      this.voiceNotice = '';
    },

    /** Ein Knopf für beides: erst aufnehmen, dann beenden und verarbeiten. */
    toggleVoice() {
      if (this.voiceStatus === 'recording') {
        void this.stopVoice();
        return;
      }
      void this.startVoice();
    },

    isVoiceBusy() {
      return this.voiceStatus !== 'idle';
    },

    voiceTimeLabel() {
      return formatRecordingTime(this.voiceSeconds);
    },

    /** Restzeit als Hinweis, sobald die letzte halbe Minute läuft. */
    voiceRemainingLabel() {
      const remaining = this.voiceMaxSeconds - this.voiceSeconds;

      return remaining <= 30 ? `noch ${formatRecordingTime(Math.max(0, remaining))}` : '';
    },

    dismissVoiceError() {
      this.voiceError = '';
      this.voiceNotice = '';
    },
  };
}

export function isTooShort(recording) {
  return !recording || !recording.blob || recording.blob.size < MIN_BLOB_BYTES;
}

export function voiceFormData(recording) {
  const body = new FormData();
  body.append('audio', recording.blob, recording.filename);
  body.append('seconds', String(recording.seconds || 0));

  return body;
}

export function formatRecordingTime(seconds) {
  const total = Math.max(0, Math.floor(Number(seconds) || 0));
  const minutes = Math.floor(total / 60);

  return `${minutes}:${String(total % 60).padStart(2, '0')}`;
}
