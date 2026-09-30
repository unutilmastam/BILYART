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

type Phase = 'opening' | 'searching' | 'holding' | 'manual' | 'captured' | 'cameraError';

/**
 * One customer photo with the tablet's front camera (spec STEP 5–7). Face
 * detection only times the shot. If the detector cannot load, a clearly
 * labelled manual button takes the photo instead — nothing is faked.
 */
export function PhotoScreen(props: { required: boolean; uploading: boolean; error: string | null; onPhoto: (photo: Blob) => void; onSkip: () => void; onCancel: () => void; deps?: PhotoDeps }) {
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
        if (!stopped) setPhase('manual');
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
        : phase === 'manual'
          ? t('photo.detectorUnavailable')
          : phase === 'cameraError'
            ? t('photo.cameraError')
            : t('photo.lookingForFace');

  return (
    <div className="flex h-full flex-col items-center justify-center gap-6 p-6">
      <h1 className="text-4xl font-bold">{t('photo.title')}</h1>
      <div className={`relative aspect-video w-full max-w-3xl overflow-hidden rounded-3xl bg-black ring-8 ${phase === 'holding' ? 'ring-brand-500' : 'ring-slate-700'}`}>
        <video ref={video} className="size-full -scale-x-100 object-cover" playsInline muted aria-label={t('photo.title')} />
        <div className="pointer-events-none absolute inset-[15%_30%] rounded-[50%] border-4 border-dashed border-white/60" aria-hidden />
      </div>
      <p className="text-xl text-slate-300">{t('photo.hint')}</p>
      <p role="status" className="flex items-center gap-3 text-2xl font-semibold">
        {(props.uploading || phase === 'opening' || phase === 'captured') && <Spinner />}
        {status}
      </p>
      {props.error && (
        <p role="alert" className="rounded-xl bg-red-600 px-6 py-3 text-2xl font-semibold">
          {props.error}
        </p>
      )}
      <div className="flex gap-4">
        <BigButton variant="ghost" onClick={props.onCancel} disabled={props.uploading}>
          {t('common.cancel')}
        </BigButton>
        {phase === 'manual' && (
          <BigButton onClick={() => void take()} disabled={props.uploading}>
            {t('photo.manual')}
          </BigButton>
        )}
        {!props.required && (
          <BigButton variant="ghost" onClick={props.onSkip} disabled={props.uploading}>
            {t('photo.skip')}
          </BigButton>
        )}
      </div>
    </div>
  );
}
