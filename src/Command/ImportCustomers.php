<?php

namespace n2305\ShopwareCustomerImport\Command;

use Exception;
use Illuminate\Support\Arr;
use League\Csv\Reader;
use n2305\ShopwareCustomerImport\ShopwareApi;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Yaml\Parser as YamlParser;

use function League\Csv\delimiter_detect;

class ImportCustomers extends Command
{
    protected static $defaultName = 'import-customers';

    /** @var LoggerInterface */
    protected $logger;

    /** @var YamlParser */
    protected $yamlParser;

    /** @var ShopwareApi */
    protected $shopwareApi;

    /** @var array */
    protected $countryMap;

    /** @var array */
    protected $mapping;

    /** @var array */
    protected $fakeOrder;

    public function __construct(LoggerInterface $logger, YamlParser $yamlParser, ShopwareApi $shopwareApi)
    {
        $this->logger = $logger;
        $this->yamlParser = $yamlParser;
        $this->shopwareApi = $shopwareApi;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Imports customers into shopware from a csv file')
            ->addArgument('source', InputArgument::REQUIRED, 'The source csv file')
            ->addArgument(
                'column-mapping',
                InputArgument::REQUIRED,
                'The column mapping file, that defines which columns from the source csv file should be '
                . 'used for which shopware user field'
            )
            ->addOption(
                'sw-countries-json',
                null,
                InputOption::VALUE_REQUIRED,
                'Export of the target shops s_core_countries table in json format',
                'data/s_core_countries.json'
            )
            ->addOption(
                'sw-fake-order-json',
                null,
                InputOption::VALUE_REQUIRED,
                'Data for the fake order that will be created after customer creation in json format',
                'data/fake-order.json',
            )
            ->addOption('group-key', 'g', InputOption::VALUE_OPTIONAL, 'Target customer group in shopware')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->mapping = $this->loadMapping($input->getArgument('column-mapping'));
        $this->countryMap = $this->loadCountryMap($input->getOption('sw-countries-json'));
        $this->fakeOrder = $this->loadFakeOrder($input->getOption('sw-fake-order-json'));

        $groupKey = $input->hasOption('group-key') ? $input->getOption('group-key') : null;

        $source = Reader::createFromPath($input->getArgument('source'));
        $delimiter = $this->detectCsvDelimiter($source);

        $source->setDelimiter($delimiter);
        $source->setHeaderOffset(0);

        $recIter = $source->getRecords();

        foreach ($recIter as $record) {
            try {
                $this->importCustomer($output, $record, $groupKey);
            } catch (Exception $e) {
                $this->logger->error('Failed to import customer', [
                    'record' => $record,
                    'e' => $e,
                ]);
            }
        }

        return Command::SUCCESS;
    }

    protected function detectCsvDelimiter(Reader $source): ?string
    {
        $delimiterInfo = delimiter_detect($source, [';', ',', "\t"], 5);
        arsort($delimiterInfo, SORT_NUMERIC);
        reset($delimiterInfo);

        if (((int) current($delimiterInfo)) === 0)
            return null;

        return key($delimiterInfo);
    }

    protected function loadMapping(string $filePath): array
    {
        $mapping = $this->yamlParser->parseFile($filePath);

        return array_map(
            static function ($sourceColumns): array {
                return is_array($sourceColumns)
                    ? $sourceColumns
                    : array_filter([$sourceColumns]);
            },
            $mapping
        );
    }

    protected function loadCountryMap(string $filePath): array
    {
        $fileInfo = new SplFileInfo($filePath);
        if (!$fileInfo->isReadable() || !$fileInfo->isFile()) {
            throw new RuntimeException("Failed to open `$filePath`");
        }

        $fileContent = file_get_contents($fileInfo->getPathname());
        $data = json_decode($fileContent, true);

        return array_column($data, 'id', 'countryname');
    }

    protected function loadFakeOrder(string $filePath): array
    {
        $fileInfo = new SplFileInfo($filePath);
        if (!$fileInfo->isReadable() || !$fileInfo->isFile()) {
            throw new RuntimeException("Failed to open `$filePath`");
        }

        $fileContent = file_get_contents($fileInfo->getPathname());
        $data = json_decode($fileContent, true);

        return $data;
    }

    protected function importCustomer(OutputInterface $output, array $record, ?string $groupKey): void
    {
        // map customer data
        $customerData = $this->createCustomerDataFromRecord($record);
        if ($groupKey) $customerData['groupKey'] = $groupKey;

        // search for customer email in shopware
        $customerId = $this->shopwareApi->findCustomerIdByEmail($customerData['email']);

        // create or update customer in shopware
        if ($customerId !== null) {
            $output->writeln("Updating customer {$customerId} {$customerData['email']}");
            $this->updateCustomer($customerId, $customerData);
        } else {
            $output->writeln("Creating customer {$customerData['email']}");
            $customerId = $this->createCustomer($customerData);
            $output->writeln("Creating fake order for customer {$customerId} {$customerData['email']}'");

            $this->createFakeOrder($customerId);
        }
    }

    protected function createCustomerDataFromRecord(array $record): array
    {
        // map customer data
        $customerData = $this->mapRecordIntoCustomerData($record);

        // map country into sw country id
        Arr::set(
            $customerData,
            'billing.country',
            $this->mapCountryIntoSwCountryId($customerData['billing']['country'])
        );

        // ensure defaults
        static $defaults = [
            'salutation' => 'mr',
            'billing' => [
                'salutation' => 'mr',
            ],
        ];

        return array_replace_recursive($defaults, $customerData);
    }

    protected function mapRecordIntoCustomerData(array $record): array
    {
        $customerData = [];

        foreach ($this->mapping as $targetKey => $sourceKeys) {
            $values = array_map(
                static function (string $sourceKey) use ($record): ?string {
                    return $record[$sourceKey] ?? null;
                },
                $sourceKeys
            );

            $values = array_filter(
                $values,
                static function (?string $value) {
                    return !empty($value);
                }
            );

            $value = trim(implode(' ', $values));
            if (!empty($value)) Arr::set($customerData, $targetKey, $value);
        }

        return $customerData;
    }

    protected function mapCountryIntoSwCountryId($country): ?int
    {
        return is_numeric((string) $country)
            ? (int) $country
            : ($this->countryMap[trim($country)] ?? null);
    }

    protected function createCustomer(array $customerData): int
    {
        $customerId = $this->shopwareApi->createCustomer($customerData);

        return $customerId;
    }

    protected function updateCustomer(int $customerId, array $customerData)
    {
        $this->shopwareApi->updateCustomer($customerId, $customerData);
    }

    protected function createFakeOrder(int $customerId)
    {
        $this->shopwareApi->createOrder(array_merge($this->fakeOrder, [
            'customerId' => $customerId,
        ]));
    }
}
