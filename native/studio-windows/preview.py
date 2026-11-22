"""Seekable playback files for the local timeline. Renderers still read the originals."""
import functools
import hashlib
import os
import re
import shutil
import subprocess
import tempfile
import threading
from pathlib import Path

import media

# Limit simultaneous software encodes, working set, bitrate and job time on the editing PC.
_ENCODE_SLOTS = threading.BoundedSemaphore(2)
_PROBE_SLOTS = threading.BoundedSemaphore(4)
_KEY_LOCKS = [threading.Lock() for _ in range(16)]
_FOLDER_LOCK = threading.Lock()
_LIMIT_INPUT = ["-format_whitelist", media.LOCAL_FORMATS + ",aac", "-protocol_whitelist", "file,pipe"]
_MAX_PROXY = 4 * 1024**3
_DISK_RESERVE = 64 * 1024**2


@functools.lru_cache(maxsize=256)
def codec_info(path, identity):
    # imageio-ffmpeg installations may have no separate ffprobe executable.
    try:
        with _PROBE_SLOTS:
            result = subprocess.run([media.ffmpeg(), "-hide_banner", "-nostdin", "-probesize", "8000000",
                                     "-analyzeduration", "6000000", *_LIMIT_INPUT, "-i", path],
                                    capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=30)
    except subprocess.TimeoutExpired as error:
        raise ValueError("Could not identify this recording's playback format in 30 seconds.") from error
    streams = re.findall(r"^\s*Stream #\d+:\d+[^\r\n]*?: (Video|Audio): ([^\r\n]+)", result.stderr, re.M)
    video = next((info for kind, info in streams if kind == "Video" and "(attached pic)" not in info), "")
    audio = next((info.split()[0].rstrip(",") for kind, info in streams if kind == "Audio"), "")
    if not (video or audio):
        raise ValueError("This recording has no previewable video or audio stream.")
    span = re.search(r"Duration:\s*(\d+):(\d+):([\d.]+)", result.stderr)
    if not span:
        raise ValueError("Finish or recover the recording before previewing it.")
    seconds = int(span[1])*3600 + int(span[2])*60 + float(span[3])
    return video, audio, seconds


def direct(suffix, video, audio, camera):
    if suffix in {".mp4", ".m4v"}:
        avc = video.startswith("h264 ") and bool(re.search(r"\b(?:yuv420p|yuvj420p)\b", video))
        return (not video or avc) and audio in {"", "aac", "mp3"}
    if suffix == ".webm":
        return (not video or video.split()[0].rstrip(",") in {"vp8", "vp9", "av1"}) and audio in {"", "opus", "vorbis"}
    if camera:
        return False
    standard = {".mp3": {"mp3"}, ".aac": {"aac"}, ".m4a": {"aac", "alac"}, ".flac": {"flac"},
                ".ogg": {"opus", "vorbis", "flac"}, ".wav": {"pcm_u8", "pcm_s16le", "pcm_s24le", "pcm_s32le", "pcm_f32le"}}
    return audio in standard.get(suffix, set())


def playable(project_root, source):
    path = media.bounded(project_root, source["path"])
    info = path.stat()
    if not path.is_file():
        raise ValueError("The source recording is missing.")
    identity = (info.st_size, info.st_mtime_ns, info.st_ino)
    video, audio, duration = codec_info(str(path), identity)
    camera = source.get("kind") == "camera"
    if direct(path.suffix.lower(), video, audio, camera):
        return path
    wants_video = camera and bool(video)
    if not wants_video and not audio:
        raise ValueError("This recording has no audio for the selected track.")
    fingerprint = repr(("browser-preview-v1", source["path"], identity, wants_video)).encode()
    key = hashlib.sha256(fingerprint).hexdigest()
    with _FOLDER_LOCK:
        folder = media.bounded(project_root, "cache/editor/proxies")
        folder.mkdir(parents=True, exist_ok=True)
    # Ranges, HEAD and another window can arrive together. Expose only complete fast-start files.
    with _KEY_LOCKS[int(key[:2], 16) % len(_KEY_LOCKS)]:
        output = media.bounded(folder, key + (".mp4" if wants_video else ".m4a"))
        if output.is_file() and output.stat().st_size:
            return output
        with _ENCODE_SLOTS:
            target_bytes_sec = 245000 if wants_video else 18000
            expected = int(duration * target_bytes_sec * 1.06) + 2*1024**2
            available = shutil.disk_usage(folder).free - _DISK_RESERVE
            if available < min(expected, _MAX_PROXY):
                raise ValueError("Free more space in the project folder to prepare this recording's preview.")
            # A shorter-than-expected output (for example when the cap is reached) must never enter the cache.
            limit = min(_MAX_PROXY, available)
            with tempfile.NamedTemporaryFile(dir=folder, suffix=output.suffix, delete=False) as temporary:
                pending = Path(temporary.name)
            try:
                args = [media.ffmpeg(), "-hide_banner", "-nostdin", "-v", "error", "-y", "-threads", "2",
                        "-filter_threads", "2", *_LIMIT_INPUT, "-i", str(path), "-map_metadata", "-1", "-map_chapters", "-1"]
                if wants_video:
                    args.extend(["-map", "0:V:0", "-vf",
                                 "fps=30,scale=w='min(iw,960)':h='min(ih,540)':force_original_aspect_ratio=decrease:force_divisible_by=2",
                                 "-c:v", "libx264", "-threads:v", "2", "-preset", "veryfast", "-pix_fmt", "yuv420p",
                                 "-crf", "25", "-maxrate", "1800k", "-bufsize", "3600k", "-g", "30"])
                if audio:
                    args.extend(["-map", "0:a:0", "-c:a", "aac", "-ac", "2", "-ar", "48000", "-b:a", "128k"])
                if audio and not wants_video:
                    args.extend(["-af", "apad", "-t", str(duration)])
                args.extend(["-movflags", "+faststart", "-fs", str(limit), str(pending)])
                try:
                    result = subprocess.run(args, capture_output=True, text=True, encoding="utf-8", errors="replace",
                                            timeout=min(8*3600, max(90, duration*2+120)))
                except subprocess.TimeoutExpired as error:
                    raise ValueError("This source timed out while preparing its editing preview.") from error
                if result.returncode:
                    raise ValueError(result.stderr[-1200:] or "Could not prepare an editing preview.")
                if not pending.stat().st_size or media.metadata(pending)["duration"] + 0.15 < duration:
                    raise ValueError("A full-length editing preview could not fit within the available size limit.")
                current = path.stat()
                if (current.st_size, current.st_mtime_ns, current.st_ino) != identity:
                    raise ValueError("The recording changed while preparing the preview. Reopen the source.")
                os.replace(pending, output)
            finally:
                pending.unlink(missing_ok=True)
    return output
