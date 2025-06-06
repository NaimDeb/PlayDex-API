<?php

namespace App\Command;

use App\Service\DatabaseOperationService;
use App\Service\ExternalApiService;
use App\Service\IgdbDataProcessorService;
use App\Service\ProgressBarHandlerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:get-genres-from-igdb',
    aliases: ['app:fetch-genres'],
    description: 'Fetches the genres from IGDB and stores them in the database.',
)]
class GetGenresFromIgdbCommand extends Command
{
    private $externalApiService;
    private $dbService;
    private $progressHandler;
    private $dataProcessor;

    public function __construct(
        ExternalApiService $externalApiService,
        DatabaseOperationService $dbService,
        ProgressBarHandlerService $progressHandler,
        IgdbDataProcessorService $dataProcessor
    ) {
        parent::__construct();
        $this->externalApiService = $externalApiService;
        $this->dbService = $dbService;
        $this->progressHandler = $progressHandler;
        $this->dataProcessor = $dataProcessor;
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Fetch games from a specific date (UNIX time)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get input options with validation
        $options = $this->validateAndGetOptions($input, $io);
        if (!$options) {
            return Command::FAILURE;
        }

        // Get total number of genres
        $xCount = $this->getGenresCount($io, $options['from']);

        // Create and configure progress bar
        $progressBar = $this->progressHandler->createSimpleProgressBar($io, $xCount);

        // Process genres in batches
        $this->processGenresInBatches($io, $xCount, $progressBar, $options['from']);

        $io->success('Genres successfully replicated in Database.');

        return Command::SUCCESS;
    }

    /**
     * Get the total count of genres from IGDB
     */
    private function getGenresCount(SymfonyStyle $io, ?int $from): int
    {
        $io->text('Fetching genres from IGDB...');
        $xCount = $this->externalApiService->getNumberOfIgdbGenres($from);
        $io->text(sprintf('Number of genres to check : %s', $xCount));

        return $xCount;
    }

    /**
     * Process genres in batches
     */
    private function processGenresInBatches(SymfonyStyle $io, int $totalCount, $progressBar, ?int $from): void
    {
        // Process first batch of genres
        $io->text('Fetching first 500 genres from IGDB...');
        $genres = $this->externalApiService->getIgdbGenres(500, from: $from);
        $this->storeIntoDatabase($genres, $progressBar);

        // Process remaining batches if needed
        if ($totalCount > 500) {
            $this->processRemainingBatches($io, $totalCount, $progressBar, $from);
        }
    }

    /**
     * Process remaining batches after the initial 500 genres
     */
    private function processRemainingBatches(SymfonyStyle $io, int $totalCount, $progressBar, ?int $from): void
    {
        for ($i = 500; $i < $totalCount; $i += 500) {
            $genres = $this->externalApiService->getIgdbGenres(500, $i, $from);
            $this->storeIntoDatabase($genres, $progressBar);
        }
    }

    /**
     * Store genres into the database
     */
    private function storeIntoDatabase(array $genres, $progressBar = null): void
    {
        // Increase memory limit
        $this->dbService->setMemoryLimit();

        // Get database connection
        $connection = $this->dbService->getConnection();

        // Prepare SQL statement
        $sql = 'INSERT INTO genre (api_id, name) 
            VALUES (:apiId, :name) 
            ON DUPLICATE KEY UPDATE 
            name = VALUES(name)';
        $stmt = $this->dbService->prepareInsertStatement($connection, $sql);

        // Execute transaction
        $this->dbService->executeTransaction(
            $connection,
            $stmt,
            $genres,
            [$this->dataProcessor, 'processGenres'],
            $progressBar
        );
    }

        /**
     * Validate input options and return them as an array
     */
    private function validateAndGetOptions(InputInterface $input, SymfonyStyle $io): ?array
    {
        $from = $input->getOption('from');

        if ($from && !is_numeric($from)) {
            $io->error('The "from" option must be a valid UNIX timestamp.');
            return null;
        }



        return [
            'from' => $from,
        ];
    }
}
