/**
 * Face *detection* only (spec §STEP 6): decides when a face is visible and
 * well placed so the single photo can be taken. No recognition, no identity,
 * no video is stored — frames are analysed in memory and dropped.
 */
export interface FaceBox {
  /** Normalised 0..1 relative to the frame. */
  x: number;
  y: number;
  width: number;
  height: number;
  score: number;
}

export interface FaceDetectorPort {
  detect(video: HTMLVideoElement, timestampMs: number): FaceBox[];
  close(): void;
}

/** One face, big enough and roughly centred. */
export function isWellPlaced(faces: FaceBox[]): boolean {
  if (faces.length !== 1) return false;
  const f = faces[0]!;
  const cx = f.x + f.width / 2;
  const cy = f.y + f.height / 2;
  return f.score >= 0.6 && f.width >= 0.18 && cx > 0.2 && cx < 0.8 && cy > 0.15 && cy < 0.85;
}

/** Loads MediaPipe BlazeFace from our own origin (WASM + model are part of the build). */
export async function loadMediapipeDetector(): Promise<FaceDetectorPort> {
  const { FaceDetector, FilesetResolver } = await import('@mediapipe/tasks-vision');
  const base = import.meta.env.BASE_URL;
  const fileset = await FilesetResolver.forVisionTasks(`${base}mediapipe`);
  const detector = await FaceDetector.createFromOptions(fileset, {
    baseOptions: { modelAssetPath: `${base}models/blaze_face_short_range.tflite`, delegate: 'CPU' },
    runningMode: 'VIDEO',
    minDetectionConfidence: 0.5,
  });
  return {
    detect(video, timestampMs) {
      const w = video.videoWidth || 1;
      const h = video.videoHeight || 1;
      return detector.detectForVideo(video, timestampMs).detections.map((d) => ({
        x: (d.boundingBox?.originX ?? 0) / w,
        y: (d.boundingBox?.originY ?? 0) / h,
        width: (d.boundingBox?.width ?? 0) / w,
        height: (d.boundingBox?.height ?? 0) / h,
        score: d.categories[0]?.score ?? 0,
      }));
    },
    close: () => detector.close(),
  };
}
