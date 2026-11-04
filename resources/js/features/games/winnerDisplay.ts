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
            color: 'bg-sky-950/40',
        };
    }

    if (winner === 'neutral') {
        return {
            label: 'The Tanner Wins',
            shortLabel: 'Tanner wins',
            icon: 'T',
            color: 'bg-yellow-950/40',
        };
    }

    return {
        label: 'The Werewolves Win',
        shortLabel: 'Werewolves win',
        icon: 'W',
        color: 'bg-red-950/40',
    };
}
