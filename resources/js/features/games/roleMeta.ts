export type RoleMeta = {
    icon: string;
    team: string;
    teamColor: string;
    bg: string;
    short: string;
};

const ROLE_META_BY_ENUM: Record<App.Enums.GameRole, RoleMeta> = {
    werewolf: {
        icon: '🐺',
        team: 'Werewolf',
        teamColor: 'text-red-400',
        bg: 'bg-red-950/60',
        short: "Wakes each night to choose a victim with the pack. On Night 1, werewolves only learn each other's identities.",
    },
    seer: {
        icon: '🔮',
        team: 'Village',
        teamColor: 'text-purple-400',
        bg: 'bg-purple-950/60',
        short: 'Investigates one player each night to learn if they are a werewolf.',
    },
    bodyguard: {
        icon: '🛡️',
        team: 'Village',
        teamColor: 'text-emerald-400',
        bg: 'bg-emerald-950/60',
        short: 'Protects one player each night from werewolf attack. Cannot guard the same player twice in a row.',
    },
    hunter: {
        icon: '🏹',
        team: 'Village',
        teamColor: 'text-amber-400',
        bg: 'bg-amber-950/60',
        short: 'When eliminated (day or night), takes one other player down with them.',
    },
    villager: {
        icon: '🧑‍🌾',
        team: 'Village',
        teamColor: 'text-blue-400',
        bg: 'bg-blue-950/60',
        short: 'No special ability. Uses discussion and logic to find the werewolves.',
    },
    tanner: {
        icon: '🪚',
        team: 'Neutral',
        teamColor: 'text-yellow-500',
        bg: 'bg-yellow-950/60',
        short: 'Wins alone if eliminated by the village during the day vote. Tries to act suspicious.',
    },
};

const DISPLAY_NAME_TO_ROLE: Record<string, App.Enums.GameRole> = {
    Werewolf: 'werewolf',
    Seer: 'seer',
    Bodyguard: 'bodyguard',
    Hunter: 'hunter',
    Villager: 'villager',
    Tanner: 'tanner',
};

export function normalizeRoleKey(role: string): App.Enums.GameRole | null {
    if (role in ROLE_META_BY_ENUM) {
        return role as App.Enums.GameRole;
    }

    return DISPLAY_NAME_TO_ROLE[role] ?? null;
}

export function roleMeta(role: string): RoleMeta | null {
    const key = normalizeRoleKey(role);

    return key ? ROLE_META_BY_ENUM[key] : null;
}

export const ROLE_META = ROLE_META_BY_ENUM;
