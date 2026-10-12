<?php declare(strict_types=1);

/** Copy the actual app; replace only IO boundaries, never scoring or routing. */
function create_isolated_app(string $root, bool $http = false): string {
    $sandbox = sys_get_temp_dir() . '/enneagram-test-' . bin2hex(random_bytes(8));
    mkdir($sandbox . '/data', 0700, true);
    file_put_contents($sandbox . '/data/invites.json', '[]');
    $source = file_get_contents($root . '/index.php');
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
            if (in_array($name, ['data_dir', 'send_result_email', 'send_mail'], true)) {
                $end = $offset; $depth = 0;
                for ($k = $i; $k < count($tokens); $k++) {
                    $part = $tokens[$k];
                    $end += strlen(is_array($part) ? $part[1] : $part);
                    if ($part === '{' || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
                    if ($part === '}' && --$depth === 0) break;
                }
                $replacement = match ($name) {
                    'data_dir' => 'function data_dir(): string { return __DIR__ . "/data"; }',
                    'send_mail' => 'function send_mail(string $to, string $subject, string $body): bool { return true; }',
                    default => 'function send_result_email(array $row): void { $GLOBALS["test_result_emails"][] = $row; file_put_contents(__DIR__ . "/data/emails.jsonl", json_encode($row).PHP_EOL, FILE_APPEND | LOCK_EX); }',
                };
                $replacements[] = [$offset, $end - $offset, $replacement];
            }
        }
        $offset += strlen($text);
    }
    if (count($replacements) !== 3) throw new RuntimeException('Isolation hooks not found');
    foreach (array_reverse($replacements) as [$start, $length, $replacement]) {
        $source = substr_replace($source, $replacement, $start, $length);
    }
    if ($http) {
        $instrumentation = <<<'PHP'
srand(42); // Test process only: repeatable shuffling, real mappings retained.
register_shutdown_function(function () {
    if (session_id() !== '') file_put_contents(__DIR__ . '/data/state-' . session_id() . '.json', json_encode($_SESSION));
});
PHP;
        $source = str_replace('<?php declare(strict_types=1);', '<?php declare(strict_types=1);' . "\n" . $instrumentation, $source);
    }
    file_put_contents($sandbox . '/index.php', $source);
    if (is_file($root.'/storage.php')) copy($root.'/storage.php',$sandbox.'/storage.php');
    foreach (['InsideBlockQuestions.csv','SeparateBlocksQuestions.csv','SeparateBlocksQuestionsTiebreak.csv','TypeDescriptions.csv'] as $csv) copy($root.'/'.$csv, $sandbox.'/'.$csv);
    return $sandbox;
}

function remove_isolated_app(string $sandbox): void {
    $resolved = realpath($sandbox);
    $tempRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || dirname($resolved) !== $tempRoot || !str_starts_with(basename($resolved), 'enneagram-test-')) throw new RuntimeException('Unsafe cleanup root');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $path = $file->getRealPath();
        if ($path === false || !str_starts_with($path, $resolved . DIRECTORY_SEPARATOR)) throw new RuntimeException('Unsafe cleanup path');
        $file->isDir() ? rmdir($path) : unlink($path);
    }
    rmdir($resolved);
}
