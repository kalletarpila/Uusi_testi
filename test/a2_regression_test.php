<?php declare(strict_types=1);

// Run: php test/a2_regression_test.php
// Execute the real application in a temporary copy, redirecting only persistence
// and result email. No historical data, repository logs, or mail are touched.
$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/enneagram-a2-' . bin2hex(random_bytes(8));
mkdir($sandbox, 0700, true);
mkdir($sandbox . '/data', 0700);
file_put_contents($sandbox . '/data/invites.json', '[]');
$source = file_get_contents($root . '/index.php');
$original = $source;
$tokens = token_get_all($source);
$offset = 0;
$replacements = [];
for ($i = 0; $i < count($tokens); $i++) {
    $token = $tokens[$i];
    $text = is_array($token) ? $token[1] : $token;
    if (is_array($token) && $token[0] === T_FUNCTION) {
        $j = $i + 1;
        while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        $name = is_array($tokens[$j]) ? $tokens[$j][1] : '';
        if (in_array($name, ['data_dir', 'send_result_email'], true)) {
            $end = $offset;
            $depth = 0;
            $opened = false;
            for ($k = $i; $k < count($tokens); $k++) {
                $part = $tokens[$k];
                $end += strlen(is_array($part) ? $part[1] : $part);
                if ($part === '{') { $depth++; $opened = true; }
                if ($part === '}' && --$depth === 0 && $opened) break;
            }
            $replacement = $name === 'data_dir'
                ? 'function data_dir(): string { return __DIR__ . "/data"; }'
                : 'function send_result_email(array $row): void {}';
            $replacements[] = [$offset, $end - $offset, $replacement];
        }
    }
    $offset += strlen($text);
}
if (count($replacements) !== 2) throw new RuntimeException('Isolation hooks not found');
foreach (array_reverse($replacements) as [$start, $length, $replacement]) {
    $source = substr_replace($source, $replacement, $start, $length);
}
file_put_contents($sandbox . '/index.php', $source);
foreach (['InsideBlockQuestions.csv', 'SeparateBlocksQuestions.csv', 'SeparateBlocksQuestionsTiebreak.csv', 'TypeDescriptions.csv'] as $csv) {
    copy($root . '/' . $csv, $sandbox . '/' . $csv);
}
chdir($sandbox);
ini_set('session.save_path', $sandbox . '/data');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = $_POST = [];
ob_start();
require $sandbox . '/index.php';
ob_end_clean();

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function seed(array $cache): void {
    $_SESSION = [
        'sessionId' => 'a2-regression', 'cached_quiz_data' => $cache,
        'points1' => ['A1'=>60, 'B1'=>30, 'C1'=>20, 'D1'=>10],
        'yesCounts1' => ['A1'=>10, 'B1'=>0, 'C1'=>0, 'D1'=>0],
        'log_row' => ['nick'=>'regression', 'finalVar'=>'', 'kutsuttu'=>'Ei'],
    ];
}
function last_csv_row(): array {
    $lines = file(log_path(), FILE_IGNORE_NEW_LINES);
    $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), ';', '"', '\\');
    $row = str_getcsv(end($lines), ';', '"', '\\');
    check(count($header) === count($row), 'CSV header/row width');
    check(count($header) === 136, 'Existing 136-column schema');
    return array_combine($header, $row);
}
function render_state(string $sandbox, array $get = [], array $post = []): string {
    file_put_contents($sandbox . '/state.json', json_encode($_SESSION));
    file_put_contents($sandbox . '/request.json', json_encode(['get'=>$get, 'post'=>$post]));
    $runner = <<<'PHP'
<?php
ini_set('session.save_path', __DIR__ . '/data');
session_id('renderfixture');
session_start();
$_SESSION = json_decode(file_get_contents(__DIR__ . '/state.json'), true);
session_write_close();
$request = json_decode(file_get_contents(__DIR__ . '/request.json'), true);
$_GET = $request['get'];
$_POST = $request['post'];
$_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
register_shutdown_function(function () {
    file_put_contents(__DIR__ . '/render-session.json', json_encode($_SESSION));
});
require __DIR__ . '/index.php';
PHP;
    file_put_contents($sandbox . '/render.php', $runner);
    $pipes = [];
    $process = proc_open([PHP_BINARY, $sandbox . '/render.php'], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, $sandbox);
    $html = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $errors === '', 'UI render without PHP errors: ' . $errors);
    return $html;
}

try {
    load_and_cache_quiz_data();
    $cache = $_SESSION['cached_quiz_data'];
    $blocks = $cache['phase2_blocks'];
    $tiebreaks = $cache['phase2_tiebreaks'];
    $expectedIds = array_merge(range(340,355,3), range(367,382,3), range(395,410,3));
    check(count($blocks['A2']) === 18, '18 A2 questions');
    check(array_column($blocks['A2'], 'question_number') === array_map('strval', $expectedIds), 'A2 CSV order and IDs');
    foreach ([['4','7'], ['4','9'], ['7','9']] as $i => $pair) {
        foreach (array_slice($blocks['A2'], $i * 6, 6) as $block) {
            check($block['pair'] === $pair && [$block['left_var'], $block['right_var']] === $pair, 'A2 pair mappings');
        }
        $tbPair = array_values(array_filter($tiebreaks['TA2'], fn($b) => $b['pair'] === $pair));
        check(count($tbPair) === 2, 'Two TA2 questions per pair');
        foreach ($tbPair as $b) check([$b['left_var'], $b['right_var']] === $pair, 'TA2 mapping');
    }
    check(array_column($tiebreaks['TA2'], 'question_number') === ['359','362','386','389','414','417'], 'TA2 order');
    echo "PASS A2: 18 standard (6 per pair), 6 tiebreak (2 per pair), order and mappings\n";

    // Compare B/C/D parsing against the actual initial Git revision, not copied fixtures.
    // The caller supplies that source through git show; only the two pure loaders run.
    $pipes = [];
    $p = proc_open(['git', '-C', $root, 'show', '1b88bfbcf9fa3b4b03e0af86af03c978dac4cad4:index.php'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $baseline = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($p) === 0 && $error === '', 'Read initial Git source');
    foreach (['load_phase2_blocks', 'load_phase2_tiebreak_all'] as $fn) {
        $start = strpos($baseline, 'function ' . $fn . '(');
        $end = strpos($baseline, "\n/**", $start);
        $definition = trim(substr($baseline, $start, $end - $start));
        eval(str_replace('function ' . $fn . '(', 'function baseline_' . $fn . '(', $definition));
    }
    $oldBlocks = baseline_load_phase2_blocks(PHASE2_FILE_BASE);
    $oldTb = baseline_load_phase2_tiebreak_all(PHASE2_FILE_BASE);
    foreach (['B2','C2','D2'] as $track) {
        check($blocks[$track] === $oldBlocks[$track], "$track parser unchanged");
        check($tiebreaks['T'.$track] === $oldTb['T'.$track], "$track TB parser unchanged");
        echo 'PASS ' . $track . ': ' . count($blocks[$track]) . ' standard, ' . count($tiebreaks['T'.$track]) . " tiebreak, identical to initial parser\n";
    }

    foreach (['normal','new_tb','old_tb'] as $route) {
        seed($cache);
        if ($route === 'normal') handle_quiz1_completion();
        if ($route === 'new_tb') {
            $_SESSION['tiebreak_classes'] = ['A1','B1'];
            $_SESSION['tb1_new_counters'] = ['A'=>5,'B'=>2,'C'=>0,'D'=>0];
            handle_tb1_new_completion();
        }
        if ($route === 'old_tb') {
            $_SESSION['tiebreak_points'] = ['A1'=>12,'B1'=>6,'C1'=>0,'D1'=>0];
            handle_tiebreak_completion();
        }
        check($_SESSION['state'] === 'phase2_intro' && $_SESSION['track'] === 'A2', "$route A1 routing");
        check($_SESSION['log_row']['winner'] === 'A1' && $_SESSION['log_row']['finalVar'] === '', "$route no premature result");
        check(count($_SESSION['blocks2']) === 18, "$route all questions");
        $ids = array_column($_SESSION['blocks2'], 'question_number'); sort($ids);
        $expected = array_map('strval', $expectedIds); sort($expected);
        check($ids === $expected, 'Shuffle preserves every question');
        foreach ($_SESSION['blocks2'] as $b) {
            $vars = [$b['left_var'], $b['right_var']]; sort($vars);
            $pair = $b['pair']; sort($pair);
            check($vars === $pair, 'Side randomization preserves mapping');
        }
    }
    echo "PASS A1 routing: normal, new Phase 1 tiebreak, old Phase 1 tiebreak\n";

    // Exercise real scoring, completion, persistence and invited/non-invited paths.
    foreach (['A2'=>['4','7','9'], 'B2'=>['3','7','8'], 'C2'=>['1','5','6'], 'D2'=>['2','6','9']] as $track => $types) {
        foreach ($types as $target) {
            foreach ([false,true] as $invited) {
                seed($cache);
                if ($invited) {
                    invites_save([['id'=>'fixture','code'=>'FIXTURE','status'=>'sent']]);
                    $_POST = ['start'=>1, 'nickname'=>'regression', 'ennea'=>'en tiedä', 'invite_code'=>'FIXTURE'];
                    check(handle_intro_form() === null, 'Invite code accepted');
                    check(invites_load()[0]['status'] === 'accepted', 'Invite accepted');
                    $_POST = [];
                }
                handle_single_winner($track[0] . '1');
                if ($invited) check(invites_load()[0]['status'] === 'accepted', 'No early invite completion');
                if ($track === 'A2' && $target === '4' && !$invited) {
                    check(str_contains(render_state($sandbox), 'Syventävät kysymykset'), 'A2 intro UI');
                }
                $_SESSION['state'] = 'phase2';
                $expectedScores = [];
                foreach ($_SESSION['blocks2'] as $i => $b) {
                    $side = (string)$b['left_var'] === $target ? 'left' : ((string)$b['right_var'] === $target ? 'right' : 'neutral');
                    $value = $side === 'neutral' ? 0 : ($i % 2 + 1);
                    if ($value) $expectedScores[$target] = ($expectedScores[$target] ?? 0) + $value;
                    if ($track === 'A2' && $target === '4' && !$invited) {
                        $html = render_state($sandbox);
                        check(str_contains($html, h($b['desc'])) && str_contains($html, h($b['left_text'])) && str_contains($html, h($b['right_text'])), 'A2 question UI');
                        check(str_contains($html, generate_progress_bar($i + 1, 18, 'Eteneminen vaihe 2')), 'A2 progress');
                    }
                    handle_phase2_answer($value, $side);
                    $entry = 'x' . ($side === 'neutral' ? 'NEUTRAL' : $target) . '-' . $b['question_number'] . '-' . $value;
                    check($_SESSION['pairs2'][$i] === $entry, 'Answer mapping and logged ID');
                }
                check($_SESSION['state'] === 'done2' && (string)$_SESSION['log_row']['finalVar'] === $target, "$track winner $target");
                check($_SESSION['varPoints'][$target] === $expectedScores[$target], 'Exact score');
                $row = last_csv_row();
                check($row['final_type'] === $target && $row['Phase2_tb'] === 'Ei', 'CSV final type');
                check($row['Case1'] === $_SESSION['pairs2'][0] && $row['Case'.count($_SESSION['pairs2'])] === end($_SESSION['pairs2']), 'CSV first/last answers');
                if ($invited) check($row['invited'] === 'Kyllä' && $row['invite_id'] === 'fixture' && invites_load()[0]['status'] === 'completed', 'Invite completion and CSV');
                if ($track === 'A2') {
                    $html = render_state($sandbox);
                    check(str_contains($html, 'TYYLI ' . $target) && !str_contains($html, 'Kuvausta ei löytynyt'), 'A2 result UI and description');
                }
                $appLog = file_get_contents(index_app_log_path());
                check(str_contains($appLog, '"winner":"'.$track[0].'1","track":"'.$track.'"'), 'Group persistence in app log');
            }
        }
        echo "PASS $track: all three winners, exact scoring, invite lifecycle, CSV persistence\n";
    }

    foreach (['A2','B2','C2','D2'] as $track) {
        foreach ($tiebreaks['T'.$track] as $candidate) {
            foreach (['left','right'] as $side) {
                seed($cache); handle_single_winner($track[0].'1');
                $pair = $candidate['pair'];
                $_SESSION['varPoints'] = array_fill_keys($pair, 10);
                initialize_phase2_tiebreak($pair);
                check($_SESSION['state'] === 'phase2_tb', "$track tiebreak entered");
                $set = $_SESSION['tb2_set'];
                foreach ($set as $b) check($b['pair'] === $pair, 'Only tied pair loaded');
                $target = (string)$candidate[$side.'_var'];
                foreach ($set as $i => $b) {
                    $answerSide = (string)$b['left_var'] === $target ? 'left' : 'right';
                    handle_phase2_tiebreak_answer(2, $answerSide);
                    check($_SESSION['tb2_pairs'][$i] === 'x'.$target.'-'.$b['question_number'].'-2', 'TB mapping/log');
                }
                check($_SESSION['varPoints'][$target] === 10 + 2 * count($set), 'Exact TB score');
                check($_SESSION['state'] === 'done2' && (string)$_SESSION['log_row']['finalVar'] === $target, 'TB result');
                $row = last_csv_row();
                check($row['Phase2_tb'] === 'Kyllä' && $row['TB2_1'] === $_SESSION['tb2_pairs'][0] && $row['final_type'] === $target, 'TB CSV');
            }
        }
        echo "PASS $track: all tiebreak pairs, both sides, exact scores and persisted answers\n";
    }

    seed($cache); handle_single_winner('A1'); $_SESSION['state'] = 'phase2';
    foreach ($_SESSION['blocks2'] as $b) handle_phase2_answer(0, 'neutral');
    check($_SESSION['state'] === 'phase2_tb' && $_SESSION['tb2_pair'] === [4,7], 'All neutral: existing first-two tie policy');
    check(str_contains(render_state($sandbox), h($_SESSION['tb2_set'][0]['desc'])), 'A2 TB UI');
    foreach ($_SESSION['tb2_set'] as $b) handle_phase2_tiebreak_answer(0, 'neutral');
    check(in_array((string)$_SESSION['log_row']['finalVar'], ['4','7','9'], true), 'Unresolved tie random fallback');
    seed($cache); handle_single_winner('A1');
    $_SESSION['varPoints'] = ['4'=>5,'9'=>5];
    $_SESSION['cached_quiz_data']['phase2_tiebreaks']['TA2'] = [];
    initialize_phase2_tiebreak(['4','9']);
    check($_SESSION['state'] === 'done2' && in_array((string)$_SESSION['log_row']['finalVar'], ['4','9'], true), 'Missing TB random fallback');
    seed($cache); handle_single_winner('A1');
    $before = $_SESSION;
    handle_phase2_answer(3, 'left');
    check($_SESSION === $before, 'Invalid answer rejected without advancing');
    $_SESSION['blocks2'] = [];
    handle_phase2_answer(1, 'left');
    check($_SESSION['state'] === 'done2' && $_SESSION['log_row']['finalVar'] === '', 'Missing block preserves existing behavior');

    seed($cache);
    unset($_SESSION['cached_quiz_data']['version'], $_SESSION['cached_quiz_data']['phase2_blocks']['A2']);
    load_and_cache_quiz_data();
    check(count($_SESSION['cached_quiz_data']['phase2_blocks']['A2']) === 18, 'Old cache refreshed');
    $fresh = $_SESSION['cached_quiz_data']; load_and_cache_quiz_data();
    check($_SESSION['cached_quiz_data'] === $fresh, 'Fresh cache preserved');
    file_put_contents('parser-semicolon.csv', "\xEF\xBB\xBF/* comment;skip\r\n\r\nA2;1 Situation\r\nA2a-4;2 Left\r\nA2b-7;3 Right\r\n");
    file_put_contents('parser-comma.csv', "A2-4vs7,1 Situation\nA2a-4,2 Left\nA2b-7,3 Right\n");
    foreach (['parser-semicolon.csv','parser-comma.csv'] as $fixture) {
        $parsed = load_phase2_blocks($fixture)['A2'];
        check(count($parsed) === 1 && $parsed[0]['pair'] === ['4','7'] && $parsed[0]['desc'] === 'Situation', 'CSV format compatibility');
    }
    check(read_tag_text_rows('absent.csv') === [], 'Missing CSV safe result');

    // Exercise actual request dispatch and invitation-token claim in the copied app.
    seed($cache); $_SESSION['state'] = 'intro';
    invites_save([['id'=>'token-fixture','token'=>'safe-test-token','status'=>'sent']]);
    render_state($sandbox, ['invite'=>'safe-test-token']);
    $_SESSION = json_decode(file_get_contents($sandbox . '/render-session.json'), true);
    check($_SESSION['InviteId'] === 'token-fixture' && invites_load()[0]['status'] === 'accepted', 'Token claim route');
    render_state($sandbox, [], ['start'=>1,'nickname'=>'token-test','ennea'=>'en tiedä']);
    $_SESSION = json_decode(file_get_contents($sandbox . '/render-session.json'), true);
    check($_SESSION['state'] === 'quiz1' && $_SESSION['InviteId'] === 'token-fixture', 'Invited start POST');
    handle_single_winner('A1');
    render_state($sandbox, [], ['continue_phase2'=>1]);
    $_SESSION = json_decode(file_get_contents($sandbox . '/render-session.json'), true);
    check($_SESSION['state'] === 'phase2', 'Phase 2 continue POST');
    $b = $_SESSION['blocks2'][0];
    render_state($sandbox, [], ['choice2_value'=>2,'choice2_side'=>'left']);
    $_SESSION = json_decode(file_get_contents($sandbox . '/render-session.json'), true);
    check($_SESSION['q2_index'] === 1 && $_SESSION['varPoints'][$b['left_var']] === 2, 'Phase 2 answer POST');
    $_SESSION['varPoints'] = ['4'=>10,'7'=>10,'9'=>0];
    initialize_phase2_tiebreak(['4','7']);
    $b = $_SESSION['tb2_set'][0];
    render_state($sandbox, [], ['tb2_value'=>1,'tb2_side'=>'right']);
    $_SESSION = json_decode(file_get_contents($sandbox . '/render-session.json'), true);
    check($_SESSION['tb2_index'] === 1 && $_SESSION['varPoints'][$b['right_var']] === 11, 'Phase 2 TB answer POST');
    echo "PASS actual POST dispatch and invitation-token route\n";
    check(hash_file('sha256', $root.'/index.php') === hash('sha256', $original), 'Project source unchanged by tests');
    echo "PASS exceptional flows, old cache refresh, comma/semicolon/BOM/comments, UI rendering\n";
    echo "PASS $checks checks; isolated data and email; no production writes\n";
} finally {
    session_write_close();
    chdir($root);
    // Resolve and constrain every deletion to the unique test directory.
    $resolved = realpath($sandbox);
    $tempRoot = realpath(sys_get_temp_dir());
    if ($resolved !== false && dirname($resolved) === $tempRoot && str_starts_with(basename($resolved), 'enneagram-a2-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, $resolved . DIRECTORY_SEPARATOR)) throw new RuntimeException('Unsafe cleanup path');
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($resolved);
    }
}
