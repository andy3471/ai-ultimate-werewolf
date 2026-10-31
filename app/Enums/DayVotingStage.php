<?php

namespace App\Enums;

enum DayVotingStage: string
{
    case CollectingNominations = 'collecting_nominations';
    case BuildingNominationResult = 'building_nomination_result';
    case NominationsExhausted = 'nominations_exhausted';
    case AwaitingSecondWindow = 'awaiting_second_window';
    case CollectingSeconds = 'collecting_seconds';
    case SecondsExhausted = 'seconds_exhausted';
    case Defense = 'defense';
    case TrialVote = 'trial_vote';
    case TrialOutcome = 'trial_outcome';
    case DyingSpeech = 'dying_speech';
    case EliminationFollowUp = 'elimination_follow_up';
    case HunterShotDyingSpeech = 'hunter_shot_dying_speech';
    case Complete = 'complete';
}
