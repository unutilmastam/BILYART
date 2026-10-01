import { useEffect, useRef, useState } from 'react';
import { BigButton, Spinner } from '../components/ui';
import { captureJpeg, openFrontCamera, stopStream } from '../lib/camera';
import { isWellPlaced, loadMediapipeDetector, type FaceDetectorPort } from '../lib/face';
import { t } from '../i18n';

/** How long a well-placed face must stay in view before the photo is taken. */
const HOLD_MS = 700;
const DETECTOR_TIMEOUT_MS = 12_000;

export interface PhotoDeps {
  openCamera: () => Promise<MediaStream>;
  loadDetector: () => Promise<FaceDetectorPort>;
  capture: (video: HTMLVideoElement) => Promise<Blob>;
}

const defaultDeps: PhotoDeps = { openCamera: openFrontCamera, loadDetector: loadMediapipeDetector, capture: (v) => captureJpeg(v) };

type Phase = 'opening' | 'searching' | 'holding' | 'captured' | 'cameraError' | 'detectorError';

/**
 * One customer photo with the tablet's front camera (spec STEP 5–7). The photo is
 * evidence for the hall (owner decision 2026-09-30), so it is taken only
 * automatically, when exactly one face is well placed for HOLD_MS — there is no
 * skip and no manual shutter. If the camera or the detector fails, the customer
 * can retry or cancel; the game never starts without a photo.
 */
export function PhotoScreen(props: { uploading: boolean; error: string | null; onPhoto: (photo: Blob) => void; onRetry: () => void; onCancel: () => void; deps?: PhotoDeps }) {
  const deps = props.deps ?? defaultDeps;
  const video = useRef<HTMLVideoElement>(null);
  const stream = useRef<MediaStream | null>(null);
  const [phase, setPhase] = useState<Phase>('opening');
  const done = useRef(false);
  const onPhoto = useRef(props.onPhoto);
  onPhoto.current = props.onPhoto;

  const take = async () => {
    if (done.current || !video.current) return;
    done.current = true;
    try {
      const blob = await deps.capture(video.current);
      stopStream(stream.current); // no continuous recording
      setPhase('captured');
      onPhoto.current(blob);
    } catch {
      done.current = false;
    }
  };

  useEffect(() => {
    let stopped = false;
    let detector: FaceDetectorPort | null = null;
    let raf = 0;
    let wellSince: number | null = null;
    let lastRun = 0;

    const loop = (ts: number) => {
      if (stopped || done.current) return;
      raf = requestAnimationFrame(loop);
      if (!detector || !video.current || video.current.readyState < 2 || ts - lastRun < 120) return;
      lastRun = ts;
      const ok = isWellPlaced(detector.detect(video.current, ts));
      if (!ok) {
        wellSince = null;
        setPhase('searching');
        return;
      }
      wellSince ??= ts;
      setPhase('holding');
      if (ts - wellSince >= HOLD_MS) void take();
    };

    (async () => {
      try {
        stream.current = await deps.openCamera();
        if (stopped) return stopStream(stream.current);
        if (video.current) {
          video.current.srcObject = stream.current;
          await video.current.play().catch(() => undefined);
        }
      } catch {
        if (!stopped) setPhase('cameraError');
        return;
      }
      setPhase('searching');
      try {
        detector = await Promise.race([
          deps.loadDetector(),
          new Promise<never>((_, reject) => setTimeout(() => reject(new Error('timeout')), DETECTOR_TIMEOUT_MS)),
        ]);
        if (stopped) return detector.close();
        raf = requestAnimationFrame(loop);
      } catch {
        if (!stopped) setPhase('detectorError');
      }
    })();

    return () => {
      stopped = true;
      cancelAnimationFrame(raf);
      detector?.close();
      stopStream(stream.current);
    };
    // Camera lifecycle is tied to the screen (mount → open, unmount → stop).
  }, []);

  const status =
    props.uploading || phase === 'captured'
      ? t('photo.uploading')
      : phase === 'holding'
        ? t('photo.hold')
        : phase === 'detectorError'
          ? t('photo.detectorFailed')
          : phase === 'cameraError'
            ? t('photo.cameraError')
            : t('photo.lookingForFace');

  return (
    <div className="flex h-full flex-col items-center justify-center gap-5 p-6">
      <div className="flex w-full max-w-3xl items-center justify-between gap-4">
        <h1 className="text-4xl font-bold tracking-tight">{t('photo.title')}</h1>
        <span className="glass rounded-full px-4 py-1.5 text-lg font-semibold text-brand-200">3 / 3</span>
      </div>
      <div
        className={`relative aspect-video w-full max-w-3xl overflow-hidden rounded-[32px] bg-black ring-4 transition ${
          phase === 'holding' ? 'ring-brand-400 shadow-[0_0_60px_-10px_rgb(56_201_153/0.8)]' : 'ring-white/15'
        }`}
      >
        <video ref={video} className="size-full -scale-x-100 object-cover" playsInline muted aria-label={t('photo.title')} />
        <div
          className={`pointer-events-none absolute inset-[14%_31%] rounded-[50%] border-4 transition ${phase === 'holding' ? 'border-brand-300' : 'border-dashed border-white/60'}`}
          aria-hidden
        />
      </div>
      <p className="text-xl text-white/75">{t('photo.hint')}</p>
      <p className="text-lg text-white/55">{t('photo.required')}</p>
      <p role="status" className="flex items-center gap-3 text-2xl font-semibold">
        {(props.uploading || phase === 'opening' || phase === 'captured') && <Spinner />}
        {status}
      </p>
      {props.error && (
        <p role="alert" className="rounded-2xl bg-red-600/90 px-6 py-3 text-2xl font-semibold">
          {props.error}
        </p>
      )}
      <div className="flex gap-4">
        <BigButton variant="ghost" onClick={props.onCancel} disabled={props.uploading}>
          {t('common.cancel')}
        </BigButton>
        {(phase === 'detectorError' || phase === 'cameraError') && <BigButton onClick={props.onRetry}>{t('common.retry')}</BigButton>}
      </div>
    </div>
  );
}
