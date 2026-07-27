const MAX_EDGE = 2560;
const MAX_BYTES = 2 * 1024 * 1024;
const JPEG_QUALITY = 0.85;

/**
 * Verkleinert Aufnahmen aus Kamera und Dateiauswahl vor dem Upload. Ein Handy
 * liefert schnell 12–48 Megapixel; der Server lässt maximal `MAX_UPLOAD_MB`
 * (Default 10) und 40 MP zu, und über Mobilfunk wäre das Original ohnehin
 * unnötig teuer. Schlägt etwas fehl, geht die Originaldatei raus - die
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
