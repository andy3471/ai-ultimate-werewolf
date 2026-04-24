<?php

test('day discussion speech count query clears relation ordering before grouping', function () {
    $path = dirname(__DIR__, 2).'/app/Services/GameSteps/DayDiscussionStepRunner.php';
    $contents = file_get_contents($path);

    expect($contents)->not->toBeFalse();
    expect($contents)->toContain('->reorder()');
    expect($contents)->toContain("->groupBy('actor_player_id')");
});
