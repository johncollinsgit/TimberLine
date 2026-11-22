# Everbranch Studio for Windows

Open **start-studio.cmd** to start the editor. On John's PC the installed copy is in **G:\Everbranch\Studio**. New episode folders default to its **projects** directory. Original recordings, edits, previews and exports live inside the selected episode folder. Set **EVERBRANCH_STUDIO_HOME** to choose a different library. A project can live on any disk with enough room for the recordings and renders.

## Set up a PC

Install Python 3.11 or newer with Tkinter and FFmpeg with DirectShow, libx264, libass, drawtext and loudnorm. Put FFmpeg on PATH, set EVERBRANCH_FFMPEG to its executable, or put a portable FFmpeg distribution in the library at tools/ffmpeg-*/bin/ffmpeg.exe. The editor also recognizes Python's imageio-ffmpeg binary. Run **setup-captions.ps1** once for English transcription. It downloads a checksum-verified whisper.cpp build and model to the Studio library. Transcription and rendering then run locally.

## Produce an episode

1. Choose **New episode**. If Everbranch has assigned an episode UUID for a sponsor rundown, enter that UUID. **Open episode** reopens episode.json. An open editor locks that project against simultaneous Studio edits.
2. Under **Recordings**, choose **Record** and select the camera or capture card and the Windows audio interface. An optional second camera records to its own file. Choose **Stop recording** to import the tracks. Alternatively import recorded video, guest voices and music. **Edit track** adjusts sync in seconds, gain in dB and inclusion in the mix. Camera audio comes from the master camera. For an imported guest video with embedded audio, also import the same file as a voice track to mix that guest microphone. When recording multiple cameras, clap once on camera to check sync.
3. Under **Delivery**, open **Episode settings & master trim** to set the primary camera, camera microphone, start/end times, and optional opening title. The master has a 16:9 frame and stereo voice levels targeted to −16 LUFS. Music is mixed below voice. Confirm music usage rights in settings before rendering a music track.
4. Use **Camera cuts** to add or adjust master angle changes. Set **Transcript → Transcribe locally**, or import SRT/WebVTT. Correct the text and cue boundaries before creating cuts. **Cut from cue** starts a cut from a selected line. **Suggest cuts** uses sentence boundaries and question openings. Review the proposed opening and ending, set wide or vertical framing, choose the camera, and enable or disable burned captions. **Preview cut** renders a small preview. Undo covers the last 20 edits in the open session.
5. Under **Products**, enter suggested catalog variant IDs with time ranges. The web editor checks maker ownership and resolves products before publication. Under **Sponsors**, import the episode's JSON rundown, place booked segments on the source timeline, and turn a placed social obligation into an editable short. The source brief and booking ID travel with the export.
6. **Render revision** creates a new immutable media set, initially marked draft. Open the revision folder to review the master MP4, MP3, WebVTT and social videos. **Approve reviewed revision** verifies the current edit and every asset checksum before recording approval. Use the web Studio to submit material, select destinations and schedule publication. Choose private, public or members visibility in the episode settings.

## Remote guest recording

Serve **public/studio-guest/** on the Everbranch HTTPS host. Share **/studio-guest/** with a guest, or run it on localhost for same-PC testing. Each person can record their own camera/microphone, or audio alone. The browser durably saves each chunk as it arrives. Open the same URL in the same browser profile after interruption, choose **Prepare download**, then **Save recording**. Keep copies until the host has imported and verified the media. Clearing site data removes local chunks. IndexedDB capacity depends on the device. Storage exhaustion stops the recorder and preserves committed chunks.

The optional peer-to-peer call exchanges an invitation and answer through a private message. For restrictive networks use a host-provided STUN/TURN configuration or your usual calling app. Microphone and camera are enabled by user action. Guests deliver finished files using the team's existing file-sharing method. Studio can rebuild an index for interrupted playable WebM/MKV/fragmented MP4 containers while keeping the original file.

## Export and checks

Each render writes **exports/<revision UUID>/studio-package.json**. It contains the stable episode UUID, revision, description, visibility, approval, file sizes, SHA-256 hashes, product tags, sponsorship placements and clip paths. Master product tag times are relative to the delivered video including the intro. Clip tags and captions are relative to their clip. Sponsor placements retain the editable source time. Sidecar captions use WebVTT. Source files and editor undo history are excluded from the delivery folder.

Run **py -3 -B -m unittest -v test_studio** here. The tests create synthetic video and tones, render both aspect ratios, check approvals, and recover a streaming WebM. Run **node test_guest.cjs** with the repository’s Playwright dependency available and Chrome installed to exercise the call and guest recording in two isolated browser contexts with fake devices.

The desktop uses FFmpeg and whisper.cpp. See [FFmpeg](https://ffmpeg.org/), [whisper.cpp](https://github.com/ggml-org/whisper.cpp), and the [caption model](https://huggingface.co/ggerganov/whisper.cpp).
