declare namespace App.Data {
export type GameData = {
id: string;
userId: string;
status: string;
phase: string;
round: number;
winner: App.Enums.GameTeam | null;
role_distribution: { [key: string]: number } | null;
players: Array<App.Data.PlayerData>;
events: Array<App.Data.GameEventData>;
created_at: string;
};
export type GameEventData = {
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
data: { [key: string]: any } | null;
};
export type PlayerData = {
id: string;
name: string;
provider: string;
model: string;
role: App.Enums.GameRole | null;
is_alive: boolean;
personality: string;
order: number;
};
export type PlayerSlotData = {
name: string;
provider: string;
model: string;
personality: string;
};
}
declare namespace App.Enums {
export type GameRole = 'werewolf' | 'villager' | 'seer' | 'bodyguard' | 'hunter' | 'tanner';
export type GameTeam = 'village' | 'werewolves' | 'neutral';
}
