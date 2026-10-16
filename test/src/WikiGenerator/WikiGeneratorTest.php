<?php

namespace DIQA\WikiFarm\WikiGenerator;

use DIQA\WikiFarm\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \DIQA\WikiFarm\WikiGenerator\WikiGenerator
 *
 * These tests exercise {@see WikiGenerator} without touching the real
 * mysql / solr / php executables. Each binary is replaced by a small PHP
 * script ({@see mock-bin.php}) that logs its invocation into a shared log
 * file. The tests then inspect that log to assert that the correct commands
 * were executed with the expected parameters.
 *
 * Tests that would require a running MediaWiki (DB access, SOLR HTTP,
 * MediaWikiServices) are skipped on purpose - this suite focuses on the
 * command-building and shell-execution behaviour that can be verified
 * deterministically.
 */
class WikiGeneratorTest extends TestCase
{
    /** @var string */
    private string $tmpDir;

    /** @var string */
    private string $logFile;

    /** @var string */
    private string $mockBin;

    /** @var string */
    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = getcwd();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wikigen_' . uniqid();
        mkdir($this->tmpDir);

        $this->logFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'calls.log';
        $this->mockBin = __DIR__ . DIRECTORY_SEPARATOR . 'mock-bin.php';

        if (!file_exists($this->mockBin)) {
            $this->fail('Mock binary script not found: ' . $this->mockBin);
        }

        // Default: mock returns success and logs to our file.
        putenv('WIKIFARM_MOCK_LOG=' . $this->logFile);
        putenv('WIKIFARM_MOCK_EXIT=0');
        putenv('WIKIFARM_MOCK_TAG=');
    }

    protected function tearDown(): void
    {
        putenv('WIKIFARM_MOCK_LOG');
        putenv('WIKIFARM_MOCK_EXIT');
        putenv('WIKIFARM_MOCK_TAG');

        // Reset globals we touched.
        foreach ([
                     'mysqlBin', 'solrBin', 'solrCoreTemplate', 'phpBin', 'publicHtml',
                     'wgDBuser', 'wgDBpassword', 'IP', 'wgServer', 'wgWikiFarmEntryPoint',
                     'wgWikiFarmDBPattern',
                 ] as $g) {
            unset($GLOBALS[$g]);
        }

        if (is_dir($this->tmpDir)) {
            $this->rrmdir($this->tmpDir);
        }

        chdir($this->originalCwd);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Static behaviour / small helpers tested through reflection
    // ---------------------------------------------------------------------

    /**
     * The createSolrCoreCommand() method differs between Windows and *nix,
     * but both should include the wikiID and the core config folder.
     */
    public function testCreateSolrCoreCommandContainsWikiIdAndFolder(): void
    {
        $GLOBALS['solrBin'] = '/opt/solr/bin/solr';
        $generator = new WikiGenerator();

        $cmd = $this->invokePrivate(
            $generator,
            'createSolrCoreCommand',
            ['mywiki', '/tmp/my-core-conf']
        );

        $this->assertStringContainsString('mywiki', $cmd);
        $this->assertStringContainsString('/tmp/my-core-conf', $cmd);
        $this->assertStringContainsString('/opt/solr/bin/solr', $cmd);
        $this->assertStringContainsString('create_core', $cmd);
    }

    public function testRemoveSolrCoreCommandContainsWikiId(): void
    {
        $GLOBALS['solrBin'] = '/opt/solr/bin/solr';
        $generator = new WikiGenerator();

        $cmd = $this->invokePrivate(
            $generator,
            'removeSolrCoreCommand',
            ['mywiki']
        );

        $this->assertStringContainsString('mywiki', $cmd);
        $this->assertStringContainsString('delete', $cmd);
        $this->assertStringContainsString('/opt/solr/bin/solr', $cmd);
    }

    public function testGetDbNameFallsBackToDefaultPattern(): void
    {
        unset($GLOBALS['wgWikiFarmDBPattern']);

        $generator = new WikiGenerator();
        $dbName = $this->invokePrivate($generator, 'getDBName', ['foo']);

        $this->assertSame('foo_wikidb', $dbName);
    }

    public function testGetDbNameUsesPatternWhenConfigured(): void
    {
        $GLOBALS['wgWikiFarmDBPattern'] = 'farm_{wiki}_db';

        $generator = new WikiGenerator();
        $dbName = $this->invokePrivate($generator, 'getDBName', ['foo']);

        $this->assertSame('farm_foo_db', $dbName);
    }

    public function testLogPrintsPrefixedMessageToStdout(): void
    {
        $generator = new WikiGenerator();
        ob_start();
        $generator->log('hello world');
        $output = ob_get_clean();

        $this->assertStringContainsString('WikiGenerator:', $output);
        $this->assertStringContainsString('hello world', $output);
    }

    // ---------------------------------------------------------------------
    // runShellCommand() + mock binaries
    // ---------------------------------------------------------------------

    public function testRunShellCommandInvokesConfiguredBinary(): void
    {
        $generator = new WikiGenerator();

        // Build a command that calls our mock binary with recognisable args.
        $cmd = $this->mockBinCommand(['first', 'second']);

        ob_start();
        $ret = $this->invokePrivate($generator, 'runShellCommand', [$cmd]);
        ob_end_clean();

        $this->assertSame(0, $ret, 'Mock binary should exit with 0');

        $entries = $this->readLog();
        $this->assertCount(1, $entries);
        $this->assertSame(['first', 'second'], $entries[0]['argv']);
    }

    public function testRunShellCommandPropagatesExitCode(): void
    {
        putenv('WIKIFARM_MOCK_EXIT=42');

        $generator = new WikiGenerator();
        $cmd = $this->mockBinCommand(['boom']);

        ob_start();
        $ret = $this->invokePrivate($generator, 'runShellCommand', [$cmd]);
        ob_end_clean();

        $this->assertSame(42, $ret);
    }

    // ---------------------------------------------------------------------
    // createWikiDB() - full command assembly, mock mysql
    // ---------------------------------------------------------------------

    public function testCreateWikiDbRunsMysqlTwiceWithExpectedParameters(): void
    {
        $this->setUpGlobalsForDbTests();

        // Prepare a fake "tables-generated.sql" file so the shell redirect is
        // at least pointing to something readable (the mock does not read it,
        // but the command is constructed from it).
        $IP = $GLOBALS['IP'];
        mkdir("$IP/maintenance", 0777, true);
        file_put_contents("$IP/maintenance/tables-generated.sql", 'SELECT 1;');

        $generator = new WikiGenerator();
        $this->setWikiConfig($generator, [
            'wikiId'      => 'mywiki',
            'dbUser'      => 'root',
            'dbPassword'  => 'secret',
        ]);

        ob_start();
        $this->invokePrivate($generator, 'createWikiDB', []);
        ob_end_clean();

        $entries = $this->readLog();
        $this->assertCount(
            2,
            $entries,
            'createWikiDB should invoke mysql twice (CREATE DB + import tables)'
        );

        // 1) CREATE DATABASE
        $firstCall = implode(' ', $entries[0]['argv']);
        $this->assertStringContainsString('CREATE DATABASE mywiki_wikidb', $firstCall);
        $this->assertStringContainsString('-u', $firstCall);

        // 2) Import schema (the SQL file redirect doesn't appear in argv -
        //    it's handled by the shell. We just check mysql was invoked with
        //    the right database flag).
        $secondCall = implode(' ', $entries[1]['argv']);
        $this->assertStringContainsString('--database=mywiki_wikidb', $secondCall);
    }

    public function testCreateWikiDbThrowsWhenCreateDatabaseFails(): void
    {
        $this->setUpGlobalsForDbTests();
        putenv('WIKIFARM_MOCK_EXIT=1');

        $generator = new WikiGenerator();
        $this->setWikiConfig($generator, [
            'wikiId'     => 'mywiki',
            'dbUser'     => 'root',
            'dbPassword' => 'secret',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Database 'mywiki_wikidb' could not be created");

        ob_start();
        try {
            $this->invokePrivate($generator, 'createWikiDB', []);
        } finally {
            ob_end_clean();
        }
    }

    // ---------------------------------------------------------------------
    // initializeDB() - exercises php maintenance scripts
    // ---------------------------------------------------------------------

    public function testInitializeDbInvokesPhpMaintenanceScripts(): void
    {
        $this->setUpGlobalsForDbTests();

        $generator = new WikiGenerator();
        $this->setWikiConfig($generator, ['wikiId' => 'mywiki']);

        ob_start();
        $this->invokePrivate($generator, 'initializeDB', []);
        ob_end_clean();

        $entries = $this->readLog();
        // 4 php invocations: setupStore, update, WikiImport, importImages
        $this->assertCount(4, $entries);

        $joined = array_map(
            static fn (array $e) => implode(' ', $e['argv']),
            $entries
        );

        $this->assertStringContainsString('SemanticMediaWiki/maintenance/setupStore.php', $joined[0]);
        $this->assertStringContainsString('maintenance/update.php', $joined[1]);
        $this->assertStringContainsString('PageImport/maintenance/WikiImport.php', $joined[2]);
        $this->assertStringContainsString('maintenance/importImages.php', $joined[3]);

        // The $WIKI environment variable should have been exported before each call.
        foreach ($entries as $entry) {
            $this->assertSame(
                'mywiki',
                $entry['env']['WIKI'],
                'WIKI env variable should be propagated to maintenance scripts'
            );
        }
    }

    // ---------------------------------------------------------------------
    // validation
    // ---------------------------------------------------------------------

    #[DataProvider('provideInvalidWikiIds')]
    public function testReadConfigRejectsInvalidWikiIds(string $wikiId): void
    {
        $jsonFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'config.json';
        file_put_contents($jsonFile, json_encode([
            'wikiId'   => $wikiId,
            'wikiName' => 'My Wiki',
        ]));

        // We cannot reach the DB-check path here because that requires
        // MediaWikiServices. But validatePrerequisites() checks the wikiId
        // *first*, so we can test it as long as we exercise createWiki()
        // via a direct call to validatePrerequisites().
        $generator = new WikiGenerator();
        $this->setWikiConfig($generator, [
            'wikiId'     => $wikiId,
            'wikiName'   => 'My Wiki',
            'favicon'    => __FILE__,
            'logo'       => __FILE__,
            'wordmark'   => __FILE__,
            'dbUser'     => 'u',
            'dbPassword' => 'p',
        ]);
        $GLOBALS['IP'] = $this->tmpDir;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("must be alphanumeric");

        ob_start();
        try {
            $this->invokePrivate($generator, 'validatePrerequisites', []);
        } finally {
            ob_end_clean();
        }
    }

    public static function provideInvalidWikiIds(): array
    {
        return [
            'uppercase'   => ['MyWiki'],
            'hyphen'      => ['my-wiki'],
            'leadingNum'  => ['1wiki'],
            'empty'       => [''],
            'specialChar' => ['wiki!'],
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Build a shell command that executes {@see mock-bin.php} with the
     * supplied arguments.
     */
    private function mockBinCommand(array $args): string
    {
        $parts = [escapeshellarg(PHP_BINARY), escapeshellarg($this->mockBin)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        // Redirect output so phpunit doesn't get polluted.
        return implode(' ', $parts) . ' > ' . escapeshellarg($this->tmpDir . '/out.txt') . ' 2>&1';
    }

    /**
     * Configure the globals that WikiGenerator reads during the shell-command
     * tests. All "binary" globals point at the mock script.
     */
    private function setUpGlobalsForDbTests(): void
    {
        $wrapper = $this->makeMockBinaryWrapper();

        $GLOBALS['IP']            = $this->tmpDir;
        $GLOBALS['mysqlBin']      = $wrapper;
        $GLOBALS['solrBin']       = $wrapper;
        $GLOBALS['phpBin']        = $wrapper;
        $GLOBALS['publicHtml']    = $this->tmpDir . '/public';
        $GLOBALS['wgDBuser']      = 'root';
        $GLOBALS['wgDBpassword']  = 'secret';
    }

    /**
     * Create an executable wrapper (platform-aware) that runs mock-bin.php.
     *
     * On Unix we use a tiny shell script; on Windows we return a .bat file.
     * Either way, running the wrapper with arbitrary arguments ends up
     * executing `php mock-bin.php <args>`.
     */
    private function makeMockBinaryWrapper(): string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'mockbin.bat';
            $contents = "@echo off\r\n"
                . '"' . PHP_BINARY . '" "' . $this->mockBin . '" %*' . "\r\n";
            file_put_contents($path, $contents);
        } else {
            $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'mockbin.sh';
            $contents = "#!/usr/bin/env sh\n"
                . '"' . PHP_BINARY . '" "' . $this->mockBin . '" "$@"' . "\n";
            file_put_contents($path, $contents);
            chmod($path, 0755);
        }

        return $path;
    }

    private function readLog(): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $lines = array_filter(explode("\n", file_get_contents($this->logFile)));
        return array_map(static fn ($l) => json_decode($l, true), $lines);
    }

    /**
     * Call a non-public method on the generator.
     *
     * @return mixed
     */
    private function invokePrivate(object $object, string $method, array $args)
    {
        $ref = new ReflectionClass($object);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
    }

    /**
     * Inject $wikiConfig into the generator (private property).
     */
    private function setWikiConfig(WikiGenerator $generator, array $config): void
    {
        $ref = new ReflectionClass($generator);
        $prop = $ref->getProperty('wikiConfig');
        $prop->setAccessible(true);
        $prop->setValue($generator, $config);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_dir($full) && !is_link($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}