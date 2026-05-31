<?php

namespace Perk11\Viktor89\Assistant\Mcp;

require_once __DIR__ . '/../../vendor/autoload.php';

use Mcp\Capability\Logger\ClientLogger;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SQLite3;

class SqliteFullTextSearchMcpServer
{
    private readonly SQLite3 $activeSqliteDatabaseConnection;

    private LoggerInterface $logger;
    public function __construct(private readonly string $absolutePathToSqliteDatabaseFile)
    {
        if (!file_exists($this->absolutePathToSqliteDatabaseFile)) {
            throw new \RuntimeException("The specified SQLite database file does not exist at the provided path.");
        }

        $this->activeSqliteDatabaseConnection = new SQLite3($this->absolutePathToSqliteDatabaseFile);
        $this->activeSqliteDatabaseConnection->enableExceptions(true);
    }

    public function initializeAndRunServer(): void
    {
        $this->logger = new Logger('full-text-search-server');
        $this->logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));
        $mcpServerInstance = Server::builder()
            ->setServerInfo('full-text-search-server', '1.0.0')
            ->addTool(
                handler:     function (string $searchQuery, int $maximumNumberOfResults = 5) {
                    return $this->executeFullTextSearchQueryAndFetchResults($searchQuery, $maximumNumberOfResults);
                },
                name:        'search_knowledge_database_documents',
                description: 'Executes a full-text search query against the local knowledge database and retrieves full document contents. Returns up to 64kb of results. If more results are available, they will be truncated.'
            )
            ->setLogger($this->logger)
            ->build();

        $standardInputOutputTransportMechanism = new StdioTransport(logger: $logger);

        $mcpServerInstance->run($standardInputOutputTransportMechanism);
    }

    /**
     * @param string $userProvidedSearchQuery The search keywords or FTS5 match query string.
     * @param int $maximumNumberOfResults The maximum number of document records to retrieve.
     */
    public function executeFullTextSearchQueryAndFetchResults(
        string $userProvidedSearchQuery,
        int $maximumNumberOfResults = 5
    ): string {
        $sqliteFullTextSearchQueryTemplate = <<<SQL
            SELECT 
                title, 
                url, 
                content
            FROM searchable_documents 
            WHERE searchable_documents MATCH :search_query_parameter 
            ORDER BY rank 
            LIMIT :results_limit_parameter
        SQL;

        $preparedSqliteStatementObject = $this->activeSqliteDatabaseConnection->prepare($sqliteFullTextSearchQueryTemplate);

        $preparedSqliteStatementObject->bindValue(':search_query_parameter', $userProvidedSearchQuery, SQLITE3_TEXT);
        $preparedSqliteStatementObject->bindValue(':results_limit_parameter', $maximumNumberOfResults, SQLITE3_INTEGER);

        $executedSqliteResultObject = $preparedSqliteStatementObject->execute();

        $collectedDatabaseRowsArray = [];

        while ($fetchedRowAssociativeArray = $executedSqliteResultObject->fetchArray(SQLITE3_ASSOC)) {
            $collectedDatabaseRowsArray[] = $fetchedRowAssociativeArray;
        }
        $this->logger->debug("Collected database rows: " . json_encode($collectedDatabaseRowsArray, JSON_THROW_ON_ERROR| JSON_PRETTY_PRINT));

        return json_encode($collectedDatabaseRowsArray, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}

$providedDatabaseFilePathArgumentFromCommandLine = $argv[1] ?? null;

if ($providedDatabaseFilePathArgumentFromCommandLine === null) {
    file_put_contents('php://stderr', "Fatal Error: Database file path argument is required.\n");
    exit(1);
}

$sqliteMcpServerApplicationInstance = new SqliteFullTextSearchMcpServer($providedDatabaseFilePathArgumentFromCommandLine);
$sqliteMcpServerApplicationInstance->initializeAndRunServer();
