import { ref, onMounted, onUnmounted } from 'vue';

export interface GameEventData {
    id: string;
    round: number;
    phase: string;
    type: string;
    actor_player_id: string | null;
    target_player_id: string | null;
    message: string | null;
    thinking: string | null;
    public_reasoning: string | null;
    is_public: boolean;
    created_at: string;
    audio_url: string | null;
}

export interface PhaseChangedEvent {
    gameId: string;
    phase: string;
    round: number;
    description: string;
    narration: string | null;
    narration_audio_url: string | null;
}

export interface PlayerActedEvent {
    gameId: string;
    event: GameEventData;
}

export interface PlayerEliminatedEvent {
    gameId: string;
    event: GameEventData;
    playerId: string;
    role: string;
}

export interface GameEndedEvent {
    gameId: string;
    winner: string;
    message: string;
}

export interface UseGameChannelOptions {
    onEvent?: (event: GameEventData) => void;
    onPhaseChanged?: (data: PhaseChangedEvent) => void;
}

export function useGameChannel(gameId: string, options: UseGameChannelOptions = {}) {
    const currentPhase = ref<string>('');
    const currentRound = ref<number>(0);
    const phaseDescription = ref<string>('');
    const narration = ref<string | null>(null);
    const events = ref<GameEventData[]>([]);
    const eliminatedPlayerIds = ref<Set<string>>(new Set());
    const revealedRoles = ref<Map<string, string>>(new Map());
    const winner = ref<string | null>(null);
    const winnerMessage = ref<string>('');
    const isConnected = ref(false);

    let channel: any = null;

    function connect() {
        if (!window.Echo) return;

        channel = window.Echo.channel(`game.${gameId}`);

        channel.listen('.phase.changed', (data: PhaseChangedEvent) => {
            currentPhase.value = data.phase;
            currentRound.value = data.round;
            phaseDescription.value = data.description;
            narration.value = data.narration;
            options.onPhaseChanged?.(data);
        });

        channel.listen('.player.acted', (data: PlayerActedEvent) => {
            events.value.push(data.event);
            options.onEvent?.(data.event);
        });

        channel.listen('.player.eliminated', (data: PlayerEliminatedEvent) => {
            events.value.push(data.event);
            eliminatedPlayerIds.value.add(data.playerId);
            revealedRoles.value.set(data.playerId, data.role);
            options.onEvent?.(data.event);
        });

        channel.listen('.game.ended', (data: GameEndedEvent) => {
            winner.value = data.winner;
            winnerMessage.value = data.message;
        });

        isConnected.value = true;
    }

    function disconnect() {
        if (channel && window.Echo) {
            window.Echo.leave(`game.${gameId}`);
            channel = null;
        }
        isConnected.value = false;
    }

    onMounted(connect);
    onUnmounted(disconnect);

    return {
        currentPhase,
        currentRound,
        phaseDescription,
        narration,
        events,
        eliminatedPlayerIds,
        revealedRoles,
        winner,
        winnerMessage,
        isConnected,
    };
}
