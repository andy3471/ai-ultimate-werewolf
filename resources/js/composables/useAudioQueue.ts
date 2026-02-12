import { ref } from 'vue';

const queue = ref<string[]>([]);
const isPlaying = ref(false);
const muted = ref(false);

/**
 * A single reusable Audio element.
 * Reusing one element means that once the browser has allowed playback
 * (after a user gesture), subsequent plays on the same element are
 * not blocked by autoplay policy.
 */
let audioEl: HTMLAudioElement | null = null;

function getAudioElement(): HTMLAudioElement {
    if (!audioEl) {
        audioEl = new Audio();
    }
    return audioEl;
}

/**
 * Composable for managing sequential audio playback.
 * Audio clips are queued and played one at a time so voices don't overlap.
 * State is shared across all component instances (module-level refs).
 */
export function useAudioQueue() {
    function enqueue(url: string) {
        if (!url || muted.value) return;
        queue.value.push(url);
        if (!isPlaying.value) {
            playNext();
        }
    }

    function playNext() {
        if (isPlaying.value || queue.value.length === 0 || muted.value) {
            return;
        }

        const url = queue.value.shift()!;
        isPlaying.value = true;

        const audio = getAudioElement();

        // Remove old listeners before attaching new ones
        audio.onended = null;
        audio.onerror = null;

        let settled = false;
        function onDone() {
            if (settled) return;
            settled = true;
            isPlaying.value = false;
            playNext();
        }

        audio.onended = onDone;
        audio.onerror = onDone;
        audio.src = url;

        audio.play().catch((err) => {
            // Autoplay blocked — retry once after a short delay
            // (gives time for any pending user interaction to unlock audio)
            if (err.name === 'NotAllowedError') {
                console.warn('Audio autoplay blocked, will retry once in 500ms:', url);
                setTimeout(() => {
                    if (settled) return;
                    audio.play().catch(() => {
                        // Still blocked — skip this clip and move on
                        onDone();
                    });
                }, 500);
            } else {
                // Actual error (bad URL, network, etc.) — skip
                onDone();
            }
        });
    }

    function playOne(url: string) {
        if (!url) return;

        // Stop current playback
        const audio = getAudioElement();
        audio.pause();
        audio.onended = null;
        audio.onerror = null;
        isPlaying.value = false;

        // Clear the queue so only this clip plays
        const savedQueue = [...queue.value];
        queue.value = [];

        // Play the requested clip, then restore the queue
        audio.src = url;
        isPlaying.value = true;

        let settled = false;
        function onDone() {
            if (settled) return;
            settled = true;
            isPlaying.value = false;
            // Re-add the remaining queue items
            queue.value = [...savedQueue, ...queue.value];
            playNext();
        }

        audio.onended = onDone;
        audio.onerror = onDone;

        audio.play().catch(onDone);
    }

    /**
     * Unlock audio playback — call this from a user gesture (click)
     * to ensure the browser allows subsequent programmatic plays.
     */
    function unlock() {
        const audio = getAudioElement();
        // Play a silent moment to "unlock" the element
        audio.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
        audio.volume = 0;
        audio.play().then(() => {
            audio.pause();
            audio.volume = 1;
            audio.src = '';
            // Now resume any queued audio
            if (!isPlaying.value && queue.value.length > 0) {
                playNext();
            }
        }).catch(() => {
            // Still blocked — that's ok, user will interact again
            audio.volume = 1;
        });
    }

    function toggleMute() {
        muted.value = !muted.value;

        if (muted.value) {
            const audio = getAudioElement();
            audio.pause();
            audio.onended = null;
            audio.onerror = null;
            isPlaying.value = false;
            queue.value = [];
        } else {
            // Unmuting counts as user gesture — unlock and resume
            unlock();
        }
    }

    function clearQueue() {
        queue.value = [];
        const audio = getAudioElement();
        audio.pause();
        audio.onended = null;
        audio.onerror = null;
        isPlaying.value = false;
    }

    return {
        enqueue,
        playOne,
        toggleMute,
        unlock,
        clearQueue,
        muted,
        isPlaying,
        queueLength: queue,
    };
}
