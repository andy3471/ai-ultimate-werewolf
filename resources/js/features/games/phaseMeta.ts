export type PhaseMeta = {
    label: string;
    icon: string;
    bg: string;
    description: string;
};

export const PHASE_META: Record<string, PhaseMeta> = {
    lobby: {
        label: 'Lobby',
        icon: '🏠',
        bg: 'from-neutral-800 to-neutral-900',
        description: 'Waiting for game to start...',
    },
    night: {
        label: 'Night',
        icon: '🌙',
        bg: 'from-indigo-950 to-neutral-950',
        description: 'Night actions are in progress.',
    },
    dawn: {
        label: 'Dawn',
        icon: '🌅',
        bg: 'from-amber-950 to-neutral-950',
        description: 'Night actions are resolved.',
    },
    day_discussion: {
        label: 'Discussion',
        icon: '💬',
        bg: 'from-sky-950 to-neutral-950',
        description: 'Players discuss in turn; nominations start after.',
    },
    day_voting: {
        label: 'Voting',
        icon: '🗳️',
        bg: 'from-red-950 to-neutral-950',
        description: 'Nominations, second, defense, then trial vote.',
    },
    dusk: {
        label: 'Dusk',
        icon: '🌆',
        bg: 'from-orange-950 to-neutral-950',
        description: 'Trial resolved; night may follow.',
    },
    game_over: {
        label: 'Game Over',
        icon: '🏁',
        bg: 'from-neutral-800 to-neutral-900',
        description: 'The game has ended.',
    },
};

export function phaseMeta(phase: string): PhaseMeta {
    return (
        PHASE_META[phase] ?? {
            label: phase,
            icon: '❓',
            bg: 'from-neutral-800 to-neutral-900',
            description: '',
        }
    );
}

export function phaseLabel(phase: string): string {
    const meta = phaseMeta(phase);

    return `${meta.icon} ${meta.label}`;
}

export function phaseDescription(phase: string): string {
    return phaseMeta(phase).description;
}
