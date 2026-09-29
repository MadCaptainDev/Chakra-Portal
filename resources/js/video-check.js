/*
 * The Video Checker (resources/views/video-check/index.blade.php).
 *
 * Everything happens in the browser. The chosen file is played from a local
 * object URL and its sound decoded with the Web Audio API; not a byte of it is
 * uploaded. Only the verdict is posted back, and only when the editor saves it.
 *
 * Checks, each pass / warn / fail / info:
 *   shape       width:height against the destination (9:16 for Reels)
 *   resolution  the short side -- 1080 or more is right for every platform
 *   length      per destination (Shorts over 3 min are not Shorts, …)
 *   frame rate  measured while it plays, with requestVideoFrameCallback
 *   bitrate     size ÷ length -- too low looks soft once Instagram re-encodes
 *   format      MP4 travels best; MOV and friends do not always
 *   first frame black means the cover people see before it plays is black
 *   sound       loudness (≈ LUFS, the -14 social target), clipping, peaks,
 *               silence before the first sound, or no sound at all
 */

const PRESETS = {
    reel: { name: 'Instagram Reel', ratio: 9 / 16, ratioLabel: '9:16', ideal: '1080 × 1920', maxSec: 180, hardMaxSec: 900 },
    short: { name: 'YouTube Short', ratio: 9 / 16, ratioLabel: '9:16', ideal: '1080 × 1920', maxSec: 180, hardMaxSec: 180 },
    youtube: { name: 'YouTube video', ratio: 16 / 9, ratioLabel: '16:9', ideal: '1920 × 1080', maxSec: null, hardMaxSec: null },
    status: { name: 'WhatsApp Status', ratio: 9 / 16, ratioLabel: '9:16', ideal: '1080 × 1920', maxSec: 60, hardMaxSec: null },
};

const KNOWN_RATIOS = [
    [9 / 16, '9:16'], [16 / 9, '16:9'], [4 / 5, '4:5'], [1, '1:1'], [3 / 4, '3:4'], [4 / 3, '4:3'], [21 / 9, '21:9'],
];

// Decoding sound needs the whole file in memory twice over; past this a
// phone tab is likely to be killed, so the sound checks are skipped instead.
const AUDIO_LIMIT_BYTES = 400 * 1024 * 1024;

export function videoChecker(config) {
    return {
        presets: PRESETS,
        preset: 'reel',
        file: null,
        url: null,
        status: 'idle', // idle | working | done
        step: '',
        results: [],
        meta: {},
        verdict: null,
        safeZones: true,
        label: '',
        saving: false,
        saved: false,
        saveError: null,

        get isVertical() {
            return PRESETS[this.preset].ratio < 1;
        },

        pick(event) {
            const file = event.target.files?.[0];
            if (!file) return;
            this.reset();
            this.file = file;
            this.url = URL.createObjectURL(file);
            this.label = file.name.replace(/\.[^.]+$/, '');
            this.$nextTick(() => this.run());
        },

        changePreset() {
            // Same file, different destination: only the verdicts change,
            // so re-run on what was already measured rather than re-reading.
            if (this.status === 'done' && this.meta.width) {
                this.results = judge(this.meta, this.file, this.preset);
                this.verdict = worst(this.results);
                this.saved = false;
            }
        },

        reset() {
            if (this.url) URL.revokeObjectURL(this.url);
            Object.assign(this, { file: null, url: null, status: 'idle', results: [], meta: {}, verdict: null, saved: false, saveError: null });
        },

        async run() {
            this.status = 'working';
            const video = this.$refs.video;
            const meta = { width: 0, height: 0, duration: 0, fps: null, playable: true, firstLuma: null, audio: null };

            try {
                this.step = 'Reading the video…';
                await loaded(video, this.url);
                meta.width = video.videoWidth;
                meta.height = video.videoHeight;
                meta.duration = video.duration;

                this.step = 'Looking at the first frame…';
                meta.firstLuma = await frameLuma(video, Math.min(0.05, meta.duration / 2));

                this.step = 'Measuring the frame rate…';
                meta.fps = await measureFps(video);
                video.currentTime = 0;
            } catch (e) {
                meta.playable = false;
            }

            if (this.file.size <= AUDIO_LIMIT_BYTES) {
                this.step = 'Listening to the sound…';
                meta.audio = await analyseAudio(this.file);
            } else {
                meta.audio = { skipped: true };
            }

            this.meta = meta;
            this.results = judge(meta, this.file, this.preset);
            this.verdict = worst(this.results);
            this.status = 'done';
        },

        async save() {
            this.saving = true;
            this.saveError = null;
            try {
                const response = await fetch(config.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        label: this.label || null,
                        file_name: this.file.name.slice(0, 255),
                        file_size: this.file.size,
                        preset: this.preset,
                        verdict: this.verdict,
                        results: this.results.map(({ status, title, detail }) => ({ status, title, detail })),
                    }),
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                this.saved = true;
            } catch (e) {
                this.saveError = "Couldn't save. Check your connection and try again.";
            } finally {
                this.saving = false;
            }
        },

        chips() {
            const m = this.meta;
            const a = m.audio || {};
            return [
                m.width ? m.width + ' × ' + m.height : null,
                m.duration ? clock(m.duration) : null,
                m.fps ? Math.round(m.fps) + ' fps' : null,
                megabytes(this.file.size),
                m.duration ? (this.file.size * 8 / m.duration / 1e6).toFixed(1) + ' Mbps' : null,
                Number.isFinite(a.lufs) ? a.lufs.toFixed(1) + ' LUFS' : null,
            ].filter(Boolean);
        },
    };
}

// ------------------------------------------------------------------ judging

function judge(meta, file, presetKey) {
    const p = PRESETS[presetKey];
    const out = [];
    const add = (status, title, detail) => out.push({ status, title, detail });

    if (!meta.playable) {
        add('fail', "Won't play here",
            "This browser can't play the file, so many phones won't either. Export as MP4 (H.264 video, AAC sound).");
    } else {
        // Shape
        const ratio = meta.width / meta.height;
        const named = nameRatio(ratio);
        if (Math.abs(ratio - p.ratio) / p.ratio <= 0.015) {
            add('pass', 'Shape ' + p.ratioLabel, meta.width + ' × ' + meta.height + ' is the right shape for a ' + p.name + '.');
        } else {
            add('fail', 'Wrong shape (' + named + ')',
                p.name + ' needs ' + p.ratioLabel + ' (' + p.ideal + '). This is ' + meta.width + ' × ' + meta.height + ' — it will be cropped or shown with bars.');
        }

        // Resolution
        const short = Math.min(meta.width, meta.height);
        if (short >= 1080) add('pass', 'Full HD or better', short >= 2160 ? '4K — fine, though 1080p is all Instagram keeps.' : 'Sharp enough for every platform.');
        else if (short >= 720) add('warn', 'Only ' + short + 'p', 'Will look soft on phones. Export at ' + p.ideal + '.');
        else add('fail', 'Low resolution (' + short + 'p)', 'Will look blurry. Export at ' + p.ideal + '.');

        // Length
        const sec = meta.duration;
        if (sec < 3) add('warn', 'Very short (' + clock(sec) + ')', 'Under 3 seconds — check the export finished.');
        else if (p.hardMaxSec && sec > p.hardMaxSec) {
            add('fail', 'Too long (' + clock(sec) + ')', p.name + 's can be at most ' + clock(p.hardMaxSec) + '.');
        } else if (p.maxSec && sec > p.maxSec) {
            add('warn', 'Long (' + clock(sec) + ')',
                presetKey === 'status' ? 'WhatsApp Status splits videos over 1 minute into parts.' : 'Over ' + clock(p.maxSec) + ' — fewer people finish long reels, and it may not be shown as a Reel.');
        } else add('pass', 'Length ' + clock(sec), 'Fits a ' + p.name + '.');

        // Frame rate
        if (meta.fps === null) add('info', 'Frame rate not measured', "This browser couldn't measure it — usually fine.");
        else if (meta.fps >= 23 && meta.fps <= 61) add('pass', Math.round(meta.fps) + ' fps', 'A normal frame rate.');
        else add('warn', 'Unusual frame rate (' + Math.round(meta.fps) + ' fps)', 'Export at 25 or 30 fps for smooth playback.');

        // Bitrate
        if (sec > 0) {
            const mbps = file.size * 8 / sec / 1e6;
            const floor = short >= 2160 ? 15 : short >= 1080 ? 5 : 2.5;
            if (mbps < floor) add('warn', 'Low quality export (' + mbps.toFixed(1) + ' Mbps)', 'Will look blocky after the platform re-compresses it. Aim for about ' + (short >= 1080 ? '15' : '8') + ' Mbps.');
            else if (mbps > 80) add('warn', 'Very large file (' + mbps.toFixed(0) + ' Mbps)', 'More than platforms keep — 15–20 Mbps looks the same and uploads faster.');
            else add('pass', 'Good quality (' + mbps.toFixed(1) + ' Mbps)', 'Enough detail to survive re-compression.');
        }

        // First frame
        if (meta.firstLuma !== null && meta.firstLuma < 12) {
            add('warn', 'Starts on black', 'The first frame is what people see before it plays, and the default cover. Start on a real shot.');
        }
    }

    // Format
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (ext !== 'mp4' && file.type !== 'video/mp4') {
        add('warn', 'Not an MP4 (.' + ext + ')', 'MP4 (H.264) plays everywhere — MOV and others can fail on some phones and on WhatsApp.');
    }

    // Sound
    const a = meta.audio;
    if (!a || a.skipped) add('info', 'Sound not checked', 'The file is over ' + megabytes(AUDIO_LIMIT_BYTES) + ' — too big to listen to on this device.');
    else if (a.none) add('warn', 'No sound', 'No audio track found. Fine for a silent video; otherwise the export dropped the sound.');
    else if (!Number.isFinite(a.lufs) || a.lufs < -45) add('fail', 'Silent', 'There is an audio track but nothing audible on it.');
    else {
        const lufs = a.lufs;
        if (lufs < -26) add('fail', 'Very quiet (' + lufs.toFixed(0) + ' LUFS)', 'People will hear almost nothing. Raise it by about ' + Math.round(-14 - lufs) + ' dB — aim for -14 LUFS.');
        else if (lufs < -17) add('warn', 'Quiet (' + lufs.toFixed(0) + ' LUFS)', 'Quieter than other posts. Raise it by about ' + Math.round(-14 - lufs) + ' dB — aim for -14 LUFS.');
        else if (lufs > -9) add('fail', 'Much too loud (' + lufs.toFixed(0) + ' LUFS)', 'Will distort and be turned down by the platform. Lower it by about ' + Math.round(lufs + 14) + ' dB.');
        else if (lufs > -11) add('warn', 'Loud (' + lufs.toFixed(0) + ' LUFS)', 'Platforms turn this down. Aim for -14 LUFS.');
        else add('pass', 'Loudness ' + lufs.toFixed(0) + ' LUFS', 'Right around the -14 LUFS social media target.');

        if (a.clipped > 20) add('fail', 'Sound is clipping', 'The sound hits the maximum ' + a.clipped + ' times — it will crackle. Lower the level or use a limiter.');
        else if (a.peakDb > -0.5) add('warn', 'Peaks at ' + a.peakDb.toFixed(1) + ' dB', 'Can crackle after re-compression. Set a limiter to -1 dB.');

        if (a.leadingSilence > 0.7) add('warn', a.leadingSilence.toFixed(1) + 's of silence at the start', 'The first second is the hook — start the sound straight away.');
    }

    // Always worth saying for this studio: it all goes out on WhatsApp.
    add('info', 'Sending on WhatsApp?', 'Send it as a Document, not as a video — WhatsApp compresses videos and the client sees a worse copy.');

    // Worst first, so the list reads as a to-do.
    const rank = { fail: 0, warn: 1, pass: 2, info: 3 };
    return out.sort((x, y) => rank[x.status] - rank[y.status]);
}

function worst(results) {
    if (results.some((r) => r.status === 'fail')) return 'fail';
    if (results.some((r) => r.status === 'warn')) return 'warn';
    return 'pass';
}

// ------------------------------------------------------------------ video

function loaded(video, url) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('timeout')), 20000);
        video.onloadeddata = () => { clearTimeout(timer); resolve(); };
        video.onerror = () => { clearTimeout(timer); reject(new Error('cannot play')); };
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.src = url;
        video.load();
    });
}

function seek(video, time) {
    return new Promise((resolve) => {
        const timer = setTimeout(resolve, 4000);
        video.onseeked = () => { clearTimeout(timer); resolve(); };
        video.currentTime = time;
    });
}

// Average brightness (0–255) of one frame, drawn small.
async function frameLuma(video, time) {
    await seek(video, time);
    const canvas = document.createElement('canvas');
    canvas.width = 36;
    canvas.height = 64;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const px = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    let sum = 0;
    for (let i = 0; i < px.length; i += 4) sum += 0.2126 * px[i] + 0.7152 * px[i + 1] + 0.0722 * px[i + 2];
    return sum / (px.length / 4);
}

// Plays (muted) for a moment and times the frames as they are presented.
function measureFps(video) {
    if (!('requestVideoFrameCallback' in HTMLVideoElement.prototype)) return Promise.resolve(null);

    return new Promise((resolve) => {
        const times = [];
        let done = false;
        const finish = () => {
            if (done) return;
            done = true;
            video.pause();
            const gaps = times.slice(1).map((t, i) => t - times[i]).filter((g) => g > 0).sort((a, b) => a - b);
            resolve(gaps.length >= 5 ? 1 / gaps[Math.floor(gaps.length / 2)] : null);
        };
        const onFrame = (now, frame) => {
            times.push(frame.mediaTime);
            if (times.length >= 24) finish();
            else if (!done) video.requestVideoFrameCallback(onFrame);
        };
        video.requestVideoFrameCallback(onFrame);
        setTimeout(finish, 3500);
        video.play().catch(finish);
    });
}

// ------------------------------------------------------------------ sound

async function analyseAudio(file) {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return { skipped: true };

    let buffer;
    const ctx = new Ctx();
    try {
        const bytes = await file.arrayBuffer();
        buffer = await new Promise((resolve, reject) => {
            // The callback form is the one older Safari understands.
            const p = ctx.decodeAudioData(bytes, resolve, reject);
            if (p && p.catch) p.catch(reject);
        });
    } catch (e) {
        return { none: true };
    } finally {
        ctx.close?.();
    }

    if (!buffer || buffer.length === 0) return { none: true };

    // Raw peaks, clipping and the first audible moment.
    let peak = 0;
    let clipped = 0;
    let firstLoud = buffer.length;
    for (let c = 0; c < buffer.numberOfChannels; c++) {
        const data = buffer.getChannelData(c);
        for (let i = 0; i < data.length; i++) {
            const v = Math.abs(data[i]);
            if (v > peak) peak = v;
            if (v >= 0.999) clipped++;
            if (v > 0.01 && i < firstLoud) firstLoud = i;
        }
    }

    return {
        peakDb: peak > 0 ? 20 * Math.log10(peak) : -Infinity,
        clipped,
        leadingSilence: firstLoud / buffer.sampleRate,
        lufs: await integratedLoudness(buffer),
    };
}

/*
 * Integrated loudness, after ITU-R BS.1770: K-weight the signal (a high
 * shelf and a high-pass, rendered offline), take 400 ms blocks overlapping by
 * 75%, drop blocks under -70 LUFS, then drop blocks 10 LU under the mean of
 * what is left. The Web Audio biquads are close to, not exactly, the
 * standard's two filters (the same corner frequencies, gain and Q) --
 * within a fraction of a LU, plenty for "too quiet".
 */
async function integratedLoudness(buffer) {
    const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    let weighted = buffer;

    if (Offline) {
        try {
            const off = new Offline(buffer.numberOfChannels, buffer.length, buffer.sampleRate);
            const src = off.createBufferSource();
            src.buffer = buffer;
            const shelf = off.createBiquadFilter();
            shelf.type = 'highshelf';
            shelf.frequency.value = 1681.97;
            shelf.gain.value = 3.99984;
            const hp = off.createBiquadFilter();
            hp.type = 'highpass';
            hp.frequency.value = 38.135;
            hp.Q.value = 0.5003;
            src.connect(shelf).connect(hp).connect(off.destination);
            src.start();
            weighted = await off.startRendering();
        } catch (e) {
            weighted = buffer;
        }
    }

    const rate = weighted.sampleRate;
    const step = Math.floor(rate * 0.1);
    const segments = Math.floor(weighted.length / step);
    if (segments < 4) return -Infinity;

    // Mean square of each 100 ms step, summed over channels (5.1 is not a
    // thing on Instagram, so every channel weighs 1).
    const seg = new Float64Array(segments);
    for (let c = 0; c < weighted.numberOfChannels; c++) {
        const data = weighted.getChannelData(c);
        for (let s = 0; s < segments; s++) {
            let sum = 0;
            const start = s * step;
            for (let i = start; i < start + step; i++) sum += data[i] * data[i];
            seg[s] += sum / step;
        }
    }

    const blocks = [];
    for (let s = 0; s + 4 <= segments; s++) blocks.push((seg[s] + seg[s + 1] + seg[s + 2] + seg[s + 3]) / 4);

    const lufs = (z) => -0.691 + 10 * Math.log10(z);
    const abs = blocks.filter((z) => z > 0 && lufs(z) > -70);
    if (abs.length === 0) return -Infinity;

    const relGate = lufs(abs.reduce((a, b) => a + b, 0) / abs.length) - 10;
    const rel = abs.filter((z) => lufs(z) > relGate);

    return lufs(rel.reduce((a, b) => a + b, 0) / rel.length);
}

// ------------------------------------------------------------------ words

function nameRatio(r) {
    const hit = KNOWN_RATIOS.find(([v]) => Math.abs(r - v) / v < 0.02);
    return hit ? hit[1] : r.toFixed(2) + ':1';
}

function clock(sec) {
    const s = Math.round(sec);
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

function megabytes(bytes) {
    return bytes >= 1024 ** 3 ? (bytes / 1024 ** 3).toFixed(1) + ' GB' : Math.round(bytes / 1024 ** 2) + ' MB';
}

// The verdict rules on their own, without a browser -- for checking them in Node.
export const _internals = { judge, worst, nameRatio };
