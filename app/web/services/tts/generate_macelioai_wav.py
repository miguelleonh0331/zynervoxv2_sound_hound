#!/usr/bin/env python3
import argparse
import subprocess
import tempfile
from pathlib import Path

from gtts import gTTS


BASE_DIR = Path(__file__).resolve().parents[2]
FFMPEG = BASE_DIR / "venvs" / "xtts_env" / "bin" / "ffmpeg"


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Generate accelerated macelioAi TTS WAV directly, without proxies."
    )
    parser.add_argument("--text", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--lang", default="es")
    parser.add_argument("--speed", type=float, default=1.3)
    args = parser.parse_args()

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(prefix="macelioai_tts_direct_") as tmpdir:
        mp3_path = Path(tmpdir) / "speech.mp3"
        wav_path = Path(tmpdir) / "speech.wav"
        try:
            # gTTS >= 2.3 acepta 'timeout'; versiones mas viejas (p.ej. 2.2.4) no.
            tts = gTTS(
                text=args.text,
                lang=args.lang,
                tld="com",
                slow=False,
                timeout=25,
            )
        except TypeError:
            tts = gTTS(
                text=args.text,
                lang=args.lang,
                tld="com",
                slow=False,
            )
        tts.save(str(mp3_path))
        subprocess.run(
            [
                str(FFMPEG) if FFMPEG.exists() else "ffmpeg",
                "-y",
                "-hide_banner",
                "-loglevel",
                "error",
                "-i",
                str(mp3_path),
                "-ar",
                "8000",
                "-ac",
                "1",
                "-sample_fmt",
                "s16",
                str(wav_path),
            ],
            check=True,
        )
        subprocess.run(
            ["sox", str(wav_path), str(output), "tempo", str(args.speed)],
            check=True,
        )

    output.chmod(0o644)
    print(output)


if __name__ == "__main__":
    main()
