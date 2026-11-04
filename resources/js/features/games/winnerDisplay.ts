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
            label: 'The Village Wins',
            shortLabel: 'Village wins',
            icon: 'V',
            color: 'from-sky-950/50 to-dark-primary',
        };
    }

    if (winner === 'neutral') {
        return {
            label: 'The Tanner Wins',
            shortLabel: 'Tanner wins',
            icon: 'T',
            color: 'from-yellow-950/50 to-dark-primary',
        };
    }

    return {
        label: 'The Werewolves Win',
        shortLabel: 'Werewolves win',
        icon: 'W',
        color: 'from-red-950/50 to-dark-primary',
    };
}
