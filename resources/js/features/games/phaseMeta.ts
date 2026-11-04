export type PhaseMeta = {
    label: string;
    mark: string;
    bg: string;
    description: string;
};

export const PHASE_META: Record<string, PhaseMeta> = {
    lobby: {
        label: 'Lobby',
        mark: 'Lobby',
        bg: 'from-dark-tertiary to-dark-primary',
        description: 'Waiting for game to start...',
    },
    night: {
        label: 'Night',
        mark: 'Night',
        bg: 'from-slate-950 to-dark-primary',
        description: 'Night actions are in progress.',
    },
    dawn: {
        label: 'Dawn',
        mark: 'Dawn',
        bg: 'from-amber-950/80 to-dark-primary',
        description: 'Night actions are resolved.',
    },
    day_discussion: {
        label: 'Discussion',
        mark: 'Day',
        bg: 'from-sky-950/70 to-dark-primary',
        description: 'Players discuss in turn; nominations start after.',
    },
    day_voting: {
        label: 'Voting',
        mark: 'Vote',
        bg: 'from-red-950/70 to-dark-primary',
        description: 'Nominations, second, defense, then trial vote.',
    },
    dusk: {
        label: 'Dusk',
        mark: 'Dusk',
        bg: 'from-orange-950/70 to-dark-primary',
        description: 'Trial resolved; night may follow.',
    },
    game_over: {
        label: 'Game Over',
        mark: 'End',
        bg: 'from-dark-tertiary to-dark-primary',
        description: 'The game has ended.',
    },
};

export function phaseMeta(phase: string): PhaseMeta {
    return (
        PHASE_META[phase] ?? {
            label: phase,
            mark: '?',
            bg: 'from-dark-tertiary to-dark-primary',
            description: '',
        }
    );
}

export function phaseLabel(phase: string): string {
    return phaseMeta(phase).label;
}

export function phaseDescription(phase: string): string {
    return phaseMeta(phase).description;
}
