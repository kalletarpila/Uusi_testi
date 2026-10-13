<?php declare(strict_types=1);

// Included by the isolated regression harness. Targeted command:
// php test/a2_regression_test.php --tiebreak-only
function tb_event(string $event): array {
    $log = file_get_contents(index_app_log_path());
    $marker = 'PHASE2_TB ' . $event . ': ';
    $offset = strrpos($log, $marker);
    check($offset !== false, "Logged $event event");
    $json = explode("\n", substr($log, $offset + strlen($marker)))[0];
    $data = json_decode(trim($json), true);
    check(is_array($data), "Valid $event JSON");
    return $data;
}
function pair_key(array $pair): string {
    $pair = array_map('strval', $pair);
    natsort($pair);
    return implode('-', $pair);
}
function start_threeway(array $cache, string $track, array $types, int $points): void {
    seed($cache);
    handle_single_winner($track[0] . '1');
    $_SESSION['state'] = 'phase2';
    phase2_seed_scores(array_fill_keys($types, $points));
    handle_phase2_completion();
    check($_SESSION['state'] === 'phase2_tb', "$track three-way entered through completion");
    check($_SESSION['varPoints'] === array_fill_keys($types, $points), 'Initial scores preserved');
}
function check_unresolved(array $expectedTypes, array $expectedScores, bool $invited, string $sandbox): void {
    check($_SESSION['state'] === 'done2' && $_SESSION['log_row']['finalVar'] === 'RATKAISEMATON', 'Unresolved test is completed');
    check($_SESSION['log_row']['tied_types'] === $expectedTypes, 'Only highest-scoring types remain');
    $result = tb_event('RESOLUTION');
    check($result['resolution_method'] === 'UNRESOLVED_TIE' && $result['final_type'] === 'RATKAISEMATON', 'Explicit unresolved method and result');
    check($result['tied_types'] === $expectedTypes && $result['remaining_leaders'] === $expectedTypes, 'Tied candidates logged');
    check($result['scores_after'] == $expectedScores, 'All final scores logged');
    $row = last_csv_row();
    check($row['final_type'] === 'RATKAISEMATON' && $row['Phase2_tb'] === 'Kyllä', 'Completed unresolved test retained in research CSV');
    foreach ($_SESSION['tb2_pairs'] as $i => $answer) check($row['TB2_'.($i+1)] === $answer, 'Unresolved TB answers retained');
    foreach ($_SESSION['pairs2'] as $i => $answer) check($row['Case'.($i+1)] === $answer, 'Unresolved standard answers retained');
    check(end($GLOBALS['test_result_emails'])['finalVar'] === 'RATKAISEMATON', 'Completed unresolved result email invoked');
    check($row['invited'] === ($invited ? 'Kyllä' : 'Ei'), 'Unresolved invited flag preserved');
    if ($invited) check(invites_load()[0]['status'] === 'completed' && $row['invite_id'] === 'unresolved-completed', 'Unresolved invitation completed and logged');
    $html = render_state($sandbox);
    check(str_contains($html, 'Testi ei pystynyt erottamaan yhtä enneagrammityyliä.'), 'Finnish unresolved explanation');
    check(str_contains($html, '<strong>'.implode(', ', $expectedTypes).'</strong>'), 'Result displays only tied types');
    check(str_contains($html, 'Testisi on suoritettu ja vastauksesi on tallennettu tutkimusaineistoon.'), 'Completed test message');
    check(!str_contains($html, 'TYYLI ') && !str_contains($html, 'Kuvausta ei löytynyt'), 'No fabricated winner or missing-description message');
    check(!str_contains($html, 'UNRESOLVED_TIE'), 'Implementation method stays in app log');
    $csvBefore = hash_file('sha256', log_path());
    render_state($sandbox);
    check(hash_file('sha256', log_path()) === $csvBefore, 'Reload does not lose or duplicate completed result');
}
function invited_unresolved_fixture(bool $invited): void {
    if (!$invited) return;
    invites_save([['id'=>'unresolved-completed','status'=>'accepted']]);
    $_SESSION['InviteId'] = 'unresolved-completed';
    $_SESSION['log_row']['kutsuttu'] = 'Kyllä';
}

$groups = ['A2'=>['4','7','9'], 'B2'=>['3','7','8'], 'C2'=>['1','5','6'], 'D2'=>['2','6','9']];
foreach ($groups as $track => $types) {
    $configured = $tiebreaks['T'.$track];
    $expectedCounts = [];
    foreach ($configured as $b) {
        $key = pair_key($b['pair']);
        $expectedCounts[$key] = ($expectedCounts[$key] ?? 0) + 1;
    }
    check(count($expectedCounts) === 3, "$track actual CSV has all three pairs");

    foreach ($types as $target) {
        start_threeway($cache, $track, $types, 7);
        $set = $_SESSION['tb2_set'];
        $expectedQuestions = array_map('serialize', $configured); sort($expectedQuestions);
        $actualQuestions = array_map('serialize', $set); sort($actualQuestions);
        check($actualQuestions === $expectedQuestions, 'Every configured question loaded exactly once');
        $actualCounts = [];
        foreach ($set as $b) {
            $key = pair_key($b['pair']);
            $actualCounts[$key] = ($actualCounts[$key] ?? 0) + 1;
        }
        check($actualCounts == $expectedCounts, 'Configured question counts per pair preserved');
        foreach ($types as $type) {
            $participation = array_filter(array_keys($actualCounts), fn($key) => in_array($type, explode('-', $key), true));
            check(count($participation) === 2, 'Every candidate participates in two distinct pairs');
        }
        $start = tb_event('START');
        check($start['track'] === $track && $start['candidates'] === $types, 'Start logs group and all candidates');
        check($start['scores_before'] == array_fill_keys($types, 7), 'Start logs initial scores');
        check($start['question_counts'] == $expectedCounts, 'Start logs pair counts');
        check(count($start['comparison_pairs']) === 3, 'Start logs all pairs');
        $scores = array_fill_keys($types, 7);
        foreach ($set as $i => $b) {
            $side = $b['left_var'] === $target ? 'left' : ($b['right_var'] === $target ? 'right' : 'neutral');
            $value = $side === 'neutral' ? 0 : 1 + $i % 2;
            if ($value) $scores[$target] += $value;
            handle_phase2_tiebreak_answer($value, $side);
            check($_SESSION['varPoints'] === $scores, '0/1/2 scores accumulate exactly, neutral unchanged');
        }
        check($_SESSION['state'] === 'done2' && (string)$_SESSION['log_row']['finalVar'] === $target, "$track three-way winner $target");
        $result = tb_event('RESOLUTION');
        check($result['resolution_method'] === 'points' && $result['final_type'] === $target, 'Point resolution identified');
        check($result['scores_after'] == $scores && $result['scores_before'] == array_fill_keys($types, 7), 'Before/after scores persisted');
        check($result['candidates'] === $types && count($result['comparison_pairs']) === 3, 'Resolution retains all candidate/pair context');
        check($result['remaining_leaders'] === [$target], 'Only actual winner remains');
        $csv = last_csv_row();
        check($csv['final_type'] === $target && $csv['Phase2_tb'] === 'Kyllä', 'Three-way CSV result and flag');
        foreach ($_SESSION['tb2_pairs'] as $i => $answer) check($csv['TB2_'.($i+1)] === $answer, 'Every TB answer fits existing CSV columns');
        check(end($GLOBALS['test_result_emails'])['finalVar'] == $target, 'Existing result email invoked with correct type');
    }

    // A neutral standard round is a three-way tie in every group, not an empty result.
    // Completed unresolved results must remain in the dataset for both user paths.
    foreach ([false, true] as $invited) {
        seed($cache); handle_single_winner($track[0].'1'); $_SESSION['state'] = 'phase2';
        invited_unresolved_fixture($invited);
        check($_SESSION['varPoints'] === array_fill_keys($types, 0), 'Every candidate initialized at zero');
        check(count($_SESSION['blocks2']) === 18, '18 standard questions for all-neutral fixture');
        foreach ($_SESSION['blocks2'] as $b) handle_phase2_answer(0, 'neutral');
        check($_SESSION['state'] === 'phase2_tb' && count($_SESSION['tb2_set']) === count($configured), 'All-neutral standard round runs every TB pair');
        $set = $_SESSION['tb2_set'];
        foreach ($set as $b) handle_phase2_tiebreak_answer(0, 'neutral');
        check($_SESSION['varPoints'] === array_fill_keys($types, 0), 'Neutral TB awards no points');
        check_unresolved($types, array_fill_keys($types, 0), $invited, $sandbox);
    }

    seed($cache); handle_single_winner($track[0].'1'); $_SESSION['state'] = 'phase2';
    foreach ($_SESSION['blocks2'] as $b) handle_phase2_answer(0, 'neutral');
    $target = $types[2]; // The candidate systematically excluded by the old code.
    foreach ($_SESSION['tb2_set'] as $b) {
        $side = $b['left_var'] === $target ? 'left' : ($b['right_var'] === $target ? 'right' : 'neutral');
        handle_phase2_tiebreak_answer($side === 'neutral' ? 0 : 2, $side);
    }
    check((string)$_SESSION['log_row']['finalVar'] === $target, 'All-neutral standard round followed by decisive TB');

    foreach ([false, true] as $invited) {
        start_threeway($cache, $track, $types, 7);
        invited_unresolved_fixture($invited);
        foreach ($_SESSION['tb2_set'] as $b) handle_phase2_tiebreak_answer(0, 'neutral');
        check_unresolved($types, array_fill_keys($types, 7), $invited, $sandbox);

        // Start with three leaders, then retain only two after positive TB answers.
        // The lowest numbered candidate must disappear from the result message.
        start_threeway($cache, $track, $types, 7);
        invited_unresolved_fixture($invited);
        $finalLeaders = [$types[1], $types[2]];
        $given = array_fill_keys($finalLeaders, false);
        $scores = array_fill_keys($types, 7);
        foreach ($_SESSION['tb2_set'] as $b) {
            $side = 'neutral';
            foreach ($finalLeaders as $type) {
                if (!$given[$type] && pair_key($b['pair']) === pair_key([$types[0], $type])) {
                    $side = $b['left_var'] === $type ? 'left' : 'right';
                    $given[$type] = true;
                    $scores[$type]++;
                    break;
                }
            }
            handle_phase2_tiebreak_answer($side === 'neutral' ? 0 : 1, $side);
        }
        check_unresolved($finalLeaders, $scores, $invited, $sandbox);
        check(tb_event('RESOLUTION')['candidates'] === $types, 'Original three candidates retained separately from final two leaders');
    }

    // Every two-way pair keeps its questions/scoring and reports a remaining tie.
    foreach (array_keys($expectedCounts) as $key) {
        $pair = explode('-', $key);
        foreach ([false,true] as $invited) {
            seed($cache); handle_single_winner($track[0].'1');
            invited_unresolved_fixture($invited);
            $_SESSION['varPoints'] = array_fill_keys($types, 0);
            foreach ($pair as $type) $_SESSION['varPoints'][$type] = 10;
            phase2_seed_scores($_SESSION['varPoints']);
            initialize_phase2_tiebreak(array_reverse($pair));
            check(count($_SESSION['tb2_set']) === $expectedCounts[$key], 'Two-way loads configured pair count');
            foreach ($_SESSION['tb2_set'] as $i => $b) {
                check(pair_key($b['pair']) === $key, 'Two-way excludes unrelated comparisons');
                handle_phase2_tiebreak_answer(0, 'neutral');
            }
            $expectedScores = array_fill_keys($types, 0);
            foreach ($pair as $type) $expectedScores[$type] = 10;
            check_unresolved($pair, $expectedScores, $invited, $sandbox);
        }
    }

    // Remove each required pair separately, for three-way and two-way cases.
    foreach (array_keys($expectedCounts) as $missingKey) {
        foreach ([$types, explode('-', $missingKey)] as $leaders) {
            seed($cache); handle_single_winner($track[0].'1');
            invites_save([['id'=>'unresolved-fixture','status'=>'accepted']]);
            $_SESSION['InviteId'] = 'unresolved-fixture';
            $_SESSION['varPoints'] = array_fill_keys($types, 0);
            foreach ($leaders as $type) $_SESSION['varPoints'][$type] = 6;
            phase2_seed_scores($_SESSION['varPoints']);
            $_SESSION['cached_quiz_data']['phase2_tiebreaks']['T'.$track] = array_values(array_filter($configured, fn($b) => pair_key($b['pair']) !== $missingKey));
            $before = $_SESSION['varPoints'];
            $csvBefore = hash_file('sha256', log_path());
            $emailsBefore = count($GLOBALS['test_result_emails']);
            initialize_phase2_tiebreak($leaders);
            check($_SESSION['state'] === 'phase2_unresolved' && $_SESSION['log_row']['finalVar'] === '', 'Missing pair explicitly unresolved');
            check($_SESSION['tb2_set'] === [] && $_SESSION['tb2_pairs'] === [] && $_SESSION['varPoints'] === $before, 'No biased partial questions or scoring');
            check(hash_file('sha256', log_path()) === $csvBefore, 'No misleading survey result written');
            check(count($GLOBALS['test_result_emails']) === $emailsBefore, 'No result email for configuration failure');
            check(invites_load()[0]['status'] === 'accepted', 'Unresolved invite not completed');
            $failure = tb_event('CONFIG_ERROR');
            check($failure['track'] === $track && $failure['missing_pairs'] === [$missingKey], 'Clear group and missing pair error');
            check($failure['resolution_method'] === 'configuration_error' && $failure['final_type'] === '', 'Failure outcome explicit in log');
            check($failure['scores_before'] == $before && $failure['scores_after'] == $before, 'Failure preserves and logs initial scores');
            session_validate_state();
            check($_SESSION['state'] === 'phase2_unresolved', 'Unresolved state survives validation');
            $html = render_state($sandbox);
            check(str_contains($html, 'Tulos jäi ratkaisematta') && !str_contains($html, 'TYYLI '), 'Participant gets explicit unresolved result');
        }
    }

    // Pair headers with mismatched scoring tags are not valid comparison questions.
    seed($cache); handle_single_winner($track[0].'1');
    phase2_seed_scores(array_fill_keys($types, 8));
    $badKey = array_key_first($expectedCounts);
    foreach ($_SESSION['cached_quiz_data']['phase2_tiebreaks']['T'.$track] as &$b) {
        if (pair_key($b['pair']) === $badKey) $b['left_var'] = 'INVALID';
    }
    unset($b);
    initialize_phase2_tiebreak($types);
    check($_SESSION['state'] === 'phase2_unresolved' && tb_event('CONFIG_ERROR')['missing_pairs'] === [$badKey], 'Malformed mapping fails safely');

    echo 'PASS ' . $track . ' three-way pairs ' . json_encode($expectedCounts) . ": all winners, zero-score rounds, completed unresolved ties (invited/non-invited), missing/malformed pairs\n";
}

// Participant output does not expose implementation details.
seed($cache); handle_single_winner('A1'); $_SESSION['varPoints'] = [4=>0,7=>0,9=>0];
phase2_seed_scores($_SESSION['varPoints']);
handle_phase2_completion();
foreach ($_SESSION['tb2_set'] as $b) handle_phase2_tiebreak_answer(0, 'neutral');
$html = render_state($sandbox);
check(!str_contains($html, 'UNRESOLVED_TIE') && !str_contains($html, 'RESOLUTION'), 'Resolution method stays in application log');
