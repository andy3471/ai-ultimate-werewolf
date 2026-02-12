import { ref } from 'vue';

const queue = ref<string[]>([]);
const isPlaying = ref(false);
const muted = ref(false);
const currentAudio = ref<HTMLAudioElement | null>(null);

/**
 * Composable for managing sequential audio playback.
 * Audio clips are queued and played one at a time so voices don't overlap.
 * State is shared across all component instances (module-level refs).
 */
export function useAudioQueue() {
    function enqueue(url: string) {
        if (!url) return;
        queue.value.push(url);
        playNext();
    }

    function playNext() {
        if (isPlaying.value || queue.value.length === 0 || muted.value) {
            return;
        }

        const url = queue.value.shift()!;
        isPlaying.value = true;

        const audio = new Audio(url);
        currentAudio.value = audio;

        // Guard against double-fire from error event + play().catch()
        let settled = false;
        function onDone() {
            if (settled) return;
            settled = true;
            isPlaying.value = false;
            currentAudio.value = null;
            playNext();
        }

        audio.addEventListener('ended', onDone);
        audio.addEventListener('error', onDone);

        audio.play().catch(onDone);
    }

    function playOne(url: string) {
        if (!url) return;

        // Stop current audio if playing
        if (currentAudio.value) {
            currentAudio.value.pause();
            currentAudio.value = null;
            isPlaying.value = false;
        }

        const audio = new Audio(url);
        currentAudio.value = audio;
        isPlaying.value = true;

        let settled = false;
        function onDone() {
            if (settled) return;
            settled = true;
            isPlaying.value = false;
            currentAudio.value = null;
            // Resume queue after manual play
            playNext();
        }

        audio.addEventListener('ended', onDone);
        audio.addEventListener('error', onDone);

        audio.play().catch(onDone);
    }

    function toggleMute() {
        muted.value = !muted.value;

        if (muted.value && currentAudio.value) {
            currentAudio.value.pause();
            currentAudio.value = null;
            isPlaying.value = false;
        }

        if (!muted.value) {
            playNext();
        }
    }

    function clearQueue() {
        queue.value = [];
        if (currentAudio.value) {
            currentAudio.value.pause();
            currentAudio.value = null;
        }
        isPlaying.value = false;
    }

    return {
        enqueue,
        playOne,
        toggleMute,
        clearQueue,
        muted,
        isPlaying,
        queueLength: queue,
    };
}
