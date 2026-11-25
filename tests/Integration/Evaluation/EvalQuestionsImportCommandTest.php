<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `neuron:eval:questions:import` against the database: the
 * first run of the fixture file creates every question, a second run of
 * the same file changes nothing, a changed question is updated in place
 * (same id), and an invalid file (duplicate key, invalid JSON) exits with
 * 1 and writes nothing. `markers` (trimmed) and `negative` of
 * `Fixtures/evaluation/retrieval-import-questions.json` are stored, a
 * re-import is unchanged, and a file without them resets them to null and
 * false (counted as updated).
 *
 * Needs the migrated test database; runs in CI. Only questions with the
 * key prefix `test-eval.` are created and removed.
 */
#[Group('database')]
final class EvalQuestionsImportCommandTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/evaluation/questions.json';
    private const string RETRIEVAL_FIXTURE = __DIR__.'/../../Fixtures/evaluation/retrieval-import-questions.json';
    private const string PREFIX = 'test-eval.';

    /** @var list<string> temporary files of the test */
    private array $files = [];

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    public function testFirstRunCreatesSecondRunChangesNothing(): void
    {
        $first = self::runCommand(self::FIXTURE);
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        self::assertStringContainsString('created: 4, updated: 0, unchanged: 0', self::display($first));

        $questions = self::questions();
        self::assertSame(['test-eval.maintenance-interval', 'test-eval.safety-training', 'test-eval.warranty', 'test-eval.wheel-nut-torque'], array_keys($questions));
        self::assertSame(EvalQuestionStatus::ACTIVE, $questions['test-eval.maintenance-interval']->getStatus());
        self::assertSame(EvalQuestionStatus::ARCHIVED, $questions['test-eval.warranty']->getStatus());
        self::assertSame(['vehicle' => 'Typ 4', 'axles' => 2], $questions['test-eval.wheel-nut-torque']->getRequestData());

        $second = self::runCommand(self::FIXTURE);
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('created: 0, updated: 0, unchanged: 4', self::display($second));
    }

    public function testChangedQuestionIsUpdatedInPlace(): void
    {
        self::runCommand(self::FIXTURE);
        $id = (string) self::questions()['test-eval.safety-training']->getId();

        $items = self::fixtureItems();
        $items[1]['question'] = 'Who organises the yearly safety training';
        $tester = self::runCommand($this->file(json_encode($items, \JSON_THROW_ON_ERROR)));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('created: 0, updated: 1, unchanged: 3', self::display($tester));
        $question = self::questions()['test-eval.safety-training'];
        self::assertSame($id, (string) $question->getId());
        self::assertSame('Who organises the yearly safety training', $question->getQuestion());
        self::assertSame(4, self::rows());
    }

    public function testMarkersAndNegativeAreStoredAndResetByTheFile(): void
    {
        $first = self::runCommand(self::RETRIEVAL_FIXTURE);
        self::assertSame(Command::SUCCESS, $first->getStatusCode(), self::display($first));
        self::assertStringContainsString('created: 3, updated: 0, unchanged: 0', self::display($first));

        $questions = self::questions();
        self::assertSame(['500 Betriebsstunden', 'Hydraulikpumpe'], $questions['test-eval.retrieval-maintenance']->getMarkers());
        self::assertFalse($questions['test-eval.retrieval-maintenance']->isNegative());
        self::assertNull($questions['test-eval.retrieval-weather']->getMarkers());
        self::assertTrue($questions['test-eval.retrieval-weather']->isNegative());
        self::assertNull($questions['test-eval.retrieval-plain']->getMarkers());
        self::assertFalse($questions['test-eval.retrieval-plain']->isNegative());

        $second = self::runCommand(self::RETRIEVAL_FIXTURE);
        self::assertStringContainsString('created: 0, updated: 0, unchanged: 3', self::display($second));

        // The file is authoritative: without the fields, markers become null
        // and negative false.
        /** @var list<array<string, mixed>> $items */
        $items = json_decode((string) file_get_contents(self::RETRIEVAL_FIXTURE), true, 512, \JSON_THROW_ON_ERROR);
        unset($items[0]['markers'], $items[1]['negative']);
        $third = self::runCommand($this->file(json_encode($items, \JSON_THROW_ON_ERROR)));
        self::assertSame(Command::SUCCESS, $third->getStatusCode(), self::display($third));
        self::assertStringContainsString('created: 0, updated: 2, unchanged: 1', self::display($third));

        $questions = self::questions();
        self::assertNull($questions['test-eval.retrieval-maintenance']->getMarkers());
        self::assertFalse($questions['test-eval.retrieval-weather']->isNegative());
    }

    public function testNegativeWithMarkersFailsAndWritesNothing(): void
    {
        /** @var list<array<string, mixed>> $items */
        $items = json_decode((string) file_get_contents(self::RETRIEVAL_FIXTURE), true, 512, \JSON_THROW_ON_ERROR);
        $items[1]['markers'] = ['Wetter'];
        $tester = self::runCommand($this->file(json_encode($items, \JSON_THROW_ON_ERROR)));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('item 1: "negative": true cannot be combined with "markers"', self::display($tester));
        self::assertSame(0, self::rows());
    }

    public function testDuplicateKeyFailsAndWritesNothing(): void
    {
        $items = self::fixtureItems();
        $items[] = $items[0];
        $tester = self::runCommand($this->file(json_encode($items, \JSON_THROW_ON_ERROR)));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('item 4: duplicate key "test-eval.maintenance-interval" (first used by item 0).', self::display($tester));
        self::assertSame(0, self::rows());
    }

    public function testInvalidJsonFailsAndWritesNothing(): void
    {
        $tester = self::runCommand($this->file('[{"key": "test-eval.broken",'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Invalid JSON', self::display($tester));
        self::assertSame(0, self::rows());
    }

    public function testMissingFileFails(): void
    {
        $tester = self::runCommand(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4)).'.json');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not exist or is not readable', self::display($tester));
    }

    private static function runCommand(string $file): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('neuron:eval:questions:import'));
        $tester->execute(['file' => $file]);

        return $tester;
    }

    /**
     * The command output with every whitespace run collapsed to one space:
     * SymfonyStyle wraps blocks at the terminal width and indents the
     * continuation lines, so a message may span lines.
     */
    private static function display(CommandTester $tester): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $tester->getDisplay()));
    }

    private function file(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'eval-questions-');
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fixtureItems(): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = json_decode((string) file_get_contents(self::FIXTURE), true, 512, \JSON_THROW_ON_ERROR);

        return $items;
    }

    /**
     * The test questions as stored, by key, read freshly from the database.
     *
     * @return array<string, EvalQuestion>
     */
    private static function questions(): array
    {
        $entityManager = self::entityManager();
        $entityManager->clear();
        $questions = [];
        foreach ($entityManager->getRepository(EvalQuestion::class)->findBy([], ['key' => 'ASC']) as $question) {
            if (str_starts_with($question->getKey(), self::PREFIX)) {
                $questions[$question->getKey()] = $question;
            }
        }

        return $questions;
    }

    private static function rows(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    private static function cleanUp(): void
    {
        self::connection()->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    private static function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
