const MAX_EDGE = 1960;
const MAX_BYTES = 2 * 1024 * 1024;
const JPEG_QUALITY = 0.82;

/**
 * Verkleinert Aufnahmen aus Kamera, Dateiauswahl, Einfügen und Drag & Drop vor
 * dem Upload - auf dieselbe Kantenlänge ("Bildschirm", 1960 px) und Qualität
 * (82 %) wie die serverseitige Massenkompression (ImageCompressionService),
 * damit neu eingefügte Bilder gar nicht erst unkomprimiert abgelegt werden.
 * Ein Handy liefert schnell 12–48 Megapixel; über Mobilfunk wäre das Original
 * ohnehin unnötig teuer. Schlägt etwas fehl, geht die Originaldatei raus - die
 * serverseitige Prüfung meldet dann verständlich, was nicht passt.
 */
export async function prepareImageForUpload(file) {
  if (!(file instanceof File) || !file.type.startsWith('image/')) {
    return file;
  }
  if (typeof createImageBitmap !== 'function' || typeof document === 'undefined') {
    return file;
  }

  let bitmap = null;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const longEdge = Math.max(bitmap.width, bitmap.height);
    if (longEdge <= MAX_EDGE && file.size <= MAX_BYTES) {
      return file;
    }

    const scale = Math.min(1, MAX_EDGE / longEdge);
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) {
      return file;
    }
    context.drawImage(bitmap, 0, 0, width, height);

    const blob = await new Promise((resolve) => {
      canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY);
    });
    if (!blob || blob.size >= file.size) {
      return file;
    }

    const name = String(file.name || 'foto').replace(/\.[^.]+$/, '');

    return new File([blob], `${name}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
  } catch (error) {
    // Formate ohne Browser-Dekoder (z. B. HEIC) landen hier.
    return file;
  } finally {
    bitmap?.close?.();
  }
}
