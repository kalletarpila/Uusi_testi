<?php declare(strict_types=1);

/** Test-only exact-score planner using the actual shuffled question mappings. */
function phase2_answer_plan(array $set, array $targets): array {
    $codes = array_keys($targets); $memo = [];
    $search = function(int $i, array $remaining) use (&$search, &$memo, $set, $codes): ?array {
        if ($i === count($set)) return array_sum($remaining) === 0 ? [] : null;
        $key = $i.':'.implode(',', $remaining); if (isset($memo[$key])) return null;
        foreach ($remaining as $type => $score) {
            $capacity = 0; for ($j=$i; $j<count($set); $j++) if (in_array((string)$type, array_map('strval', $set[$j]['pair']), true)) $capacity += 2;
            if ($score < 0 || $score > $capacity) return null;
        }
        if (array_sum($remaining) > 2 * (count($set)-$i)) return null;
        $choices = [['side'=>'neutral','value'=>0]];
        foreach (['left','right'] as $side) foreach ([1,2] as $value) $choices[] = ['side'=>$side,'value'=>$value];
        foreach ($choices as $choice) {
            $next = $remaining;
            if ($choice['value']) { $type=$set[$i][$choice['side'].'_var']; if (($next[$type]??0)<$choice['value']) continue; $next[$type]-=$choice['value']; }
            $tail = $search($i+1, $next);
            if ($tail !== null) return array_merge([$choice], $tail);
        }
        $memo[$key] = true; return null;
    };
    $answers = $search(0, $targets);
    if ($answers === null) throw new RuntimeException('Unreachable actual-question target scores: '.json_encode($targets));
    return $answers;
}

/** Function-level fixture, not an HTTP workflow or production scoring engine. */
function phase2_seed_scores(array $scores): void {
    $targets = array_fill_keys($_SESSION['varCodes'], 0);
    foreach ($scores as $type=>$points) $targets[$type]=$points;
    $_SESSION['answers2'] = phase2_answer_plan($_SESSION['blocks2'], $targets);
    $_SESSION['q2_index'] = count($_SESSION['blocks2']);
    $_SESSION['state'] = 'phase2';
    if (!recalculate_phase2_answers()) throw new RuntimeException('Invalid canonical score fixture');
}
