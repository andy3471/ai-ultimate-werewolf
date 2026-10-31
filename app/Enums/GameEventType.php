<?php

namespace App\Enums;

enum GameEventType: string
{
    case WerewolfKill = 'werewolf_kill';
    case SeerInvestigate = 'seer_investigate';
    case BodyguardProtect = 'bodyguard_protect';
    case BodyguardSave = 'bodyguard_save';
    case Discussion = 'discussion';
    case DyingSpeech = 'dying_speech';
    case Nomination = 'nomination';
    case NominationResult = 'nomination_result';
    case NominationSecond = 'nomination_second';
    case NominationBlock = 'nomination_block';
    case DefenseSpeech = 'defense_speech';
    case Vote = 'vote';
    case VoteOutcome = 'vote_outcome';
    case VoteTally = 'vote_tally';
    case VoteTie = 'vote_tie';
    case NoElimination = 'no_elimination';
    case Death = 'death';
    case Elimination = 'elimination';
    case HunterShot = 'hunter_shot';
    case HunterShotFollowupDone = 'hunter_shot_followup_done';
    case Narration = 'narration';
    case NoDeath = 'no_death';
    case GameEnd = 'game_end';
    case Error = 'error';
}
