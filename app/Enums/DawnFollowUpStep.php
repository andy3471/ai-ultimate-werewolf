<?php

namespace App\Enums;

enum DawnFollowUpStep: int
{
    case Narration = 0;
    case DyingSpeech = 1;
    case EliminationFollowUp = 2;
    case HunterShotDyingSpeech = 3;
}
