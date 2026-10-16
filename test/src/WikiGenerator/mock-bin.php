
<?php
/**
 * Mock binary used by WikiGeneratorTest.
 *
 * This script replaces real executables (mysql, solr, php, ...) during tests.
 * It logs its arguments and stdin (if present) to a log file and returns the
 * configured exit code.
 *
 * Behaviour is controlled through environment variables:
 *   WIKIFARM_MOCK_LOG      Path to the log file (JSON lines, one per invocation).
 *   WIKIFARM_MOCK_EXIT     Exit code to return (default 0).
 *   WIKIFARM_MOCK_TAG      Optional tag written to the log entry so the test
 *                          can distinguish different binaries.
 */

$logFile = getenv('WIKIFARM_MOCK_LOG');
$exitCode = (int) (getenv('WIKIFARM_MOCK_EXIT') ?: 0);
$tag = getenv('WIKIFARM_MOCK_TAG') ?: '';

$entry = [
    'tag'  => $tag,
    'argv' => array_slice($argv, 1),
    'cwd'  => getcwd(),
    'env'  => [
        'WIKI' => getenv('WIKI'),
    ],
];

if ($logFile) {
    file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND);
}

// Print something so system() returns a non-false value.
echo "mock-bin executed\n";
exit($exitCode);