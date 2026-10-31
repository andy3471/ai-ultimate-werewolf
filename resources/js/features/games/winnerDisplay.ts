export type WinnerDisplay = {
    label: string;
    icon: string;
    color: string;
    shortLabel: string;
};

export function winnerDisplay(winner: App.Enums.GameTeam | string | null): WinnerDisplay | null {
    if (!winner) {
        return null;
    }

    if (winner === 'village') {
        return {
            label: 'The Village Wins!',
            shortLabel: 'Village Wins',
            icon: '🏘️',
            color: 'from-sky-900/50 to-neutral-950',
        };
    }

    if (winner === 'neutral') {
        return {
            label: 'The Tanner Wins!',
            shortLabel: 'Tanner Wins',
            icon: '🪚',
            color: 'from-yellow-900/50 to-neutral-950',
        };
    }

    return {
        label: 'The Werewolves Win!',
        shortLabel: 'Werewolves Win',
        icon: '🐺',
        color: 'from-red-900/50 to-neutral-950',
    };
}
