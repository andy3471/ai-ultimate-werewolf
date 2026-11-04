export type RoleMeta = {
    mark: string;
    icon: string;
    label: string;
    team: string;
    teamColor: string;
    bg: string;
    short: string;
};

const ROLE_META_BY_ENUM: Record<App.Enums.GameRole, RoleMeta> = {
    werewolf: {
        mark: 'W',
        icon: 'W',
        label: 'Werewolf',
        team: 'Werewolf',
        teamColor: 'text-red-400',
        bg: 'bg-red-950/60',
        short: "Wakes each night to choose a victim with the pack. On Night 1, werewolves only learn each other's identities.",
    },
    seer: {
        mark: 'S',
        icon: 'S',
        label: 'Seer',
        team: 'Village',
        teamColor: 'text-sky-400',
        bg: 'bg-sky-950/60',
        short: 'Investigates one player each night to learn if they are a werewolf.',
    },
    bodyguard: {
        mark: 'B',
        icon: 'B',
        label: 'Bodyguard',
        team: 'Village',
        teamColor: 'text-emerald-400',
        bg: 'bg-emerald-950/60',
        short: 'Protects one player each night from werewolf attack. Cannot guard the same player twice in a row.',
    },
    hunter: {
        mark: 'H',
        icon: 'H',
        label: 'Hunter',
        team: 'Village',
        teamColor: 'text-amber-400',
        bg: 'bg-amber-950/60',
        short: 'When eliminated (day or night), takes one other player down with them.',
    },
    villager: {
        mark: 'V',
        icon: 'V',
        label: 'Villager',
        team: 'Village',
        teamColor: 'text-accent-blue',
        bg: 'bg-blue-950/60',
        short: 'No special ability. Uses discussion and logic to find the werewolves.',
    },
    tanner: {
        mark: 'T',
        icon: 'T',
        label: 'Tanner',
        team: 'Neutral',
        teamColor: 'text-accent-yellow',
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
