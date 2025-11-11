<?php

namespace App\Console\Commands;

use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\Exception;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class ImportOmopVocabs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-omop-vocabs
        {path : Path to Athena vobabulary files}
        {--truncate : Truncate existing vocabulary tables before import (default: true)}
        {--bulk : Use LOAD DATA INFILE for large tables (faster)}
        {--create-schema : Create OMOP vocabulary schema if it does not exist}
        {--clean-file : Clean input files before import (fix malformed CSVs) - THIS IS VERY SLOW! YOU SHOULD GRAB A TEA!}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = <<<DESC
Import OMOP Athena vocabulary TSVs into the OMOP database.

Notes:
- To avoid running out of memory, ensure MySQL allows local infile:
    1. Edit your my.cnf (e.g., /etc/my.cnf or /usr/local/etc/my.cnf)
       - Set `secure_file_priv = ""`
       - Set `local_infile = 1`
    2. Restart MySQL (`brew services restart mysql` on macOS)

- Athena-exported CSVs often contain malformed data. Clean them first:
    - Install csvkit (`brew install csvkit`)
    - Convert CSV to clean tab-delimited format:
      `csvformat -T FILENNAME.csv > FILENAME_clean.tsv`

- Then run the import command using the cleaned TSVs.
DESC;

    protected array $vocabFiles = [
        'VOCABULARY',
        'DOMAIN',
        'CONCEPT_CLASS',
        'RELATIONSHIP',
        'CONCEPT_RELATIONSHIP',
        'CONCEPT_SYNONYM',
        'CONCEPT_ANCESTOR',
        'CONCEPT',
        'DRUG_STRENGTH',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = rtrim($this->argument('path'), '/');
        $useBulk = $this->option('bulk');
        $shouldTruncate = $this->option('truncate') ?? true;
        $shouldClean = $this->option('clean-file') ?? false;
        $tmpFile = '';

        if ($this->option('create-schema')) {
            $this->createSchema();
        }

        if (!is_dir($path)) {
            $this->error('Path not found: ' . $path);
            return;
        }

        $this->info('Scanning folder: ' . $path);
        $files = collect(File::files($path))
            ->filter(fn($f) => str_ends_with(strtolower($f->getFilename()), '.csv'))
            ->keyBy(fn($f) => strtoupper(str_replace('.csv', '', $f->getFilename())));

        $conn = DB::connection('omop');

        foreach ($this->vocabFiles as $tableName) {
            if (!$files->has($tableName)) {
                $this->warn('Skipping ' . $tableName . ': file not found in directory');
                continue;
            }

            $file = $files->get($tableName)->getPathname();
            $table = strtolower($tableName);

            $this->info('Importing ' . $tableName . ' from ' . $file . ' to ' . 'omop.' . $table);
            if ($shouldTruncate) {
                $conn->table($table)->truncate();
                $this->line('   - truncated existing data');
            }

            if ($useBulk) {
                if ($shouldClean) {
                    $tmpFile = $this->cleanFile($file);
                }
                $this->bulkLoad($conn, $table, $tmpFile ? $tmpFile : $file);
                if ($shouldClean && $tmpFile && file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            } else {
                $this->streamLoad($conn, $table, $file);
            }

            $this->newLine();
        }

        $this->info('OMOP vocabulary import completed');
    }

    protected function bulkLoad($conn, string $table, string $file): void
    {
        // This is a massive drain on memory and performance for large files
        // configure system for large imports.
        $conn->disableQueryLog();
        ini_set('memory_limit', '8G'); // Yes, really!
        gc_enable();
        $conn->getPdo()->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE'");
        $conn->getPdo()->exec('SET SESSION max_error_count = 1000');

        $file = addslashes($file);

        $sql = <<<SQL
LOAD DATA LOCAL INFILE '$file'
INTO TABLE `$table`
FIELDS TERMINATED BY '\t'
OPTIONALLY ENCLOSED BY '"'
ESCAPED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES;
SQL;

        $start = microtime(true);
        $conn->getPdo()->exec($sql);
        $warnCount = $conn->getPdo()->query('SHOW COUNT(*) WARNINGS')->fetchColumn();
        if ($warnCount > 0) {
            $this->warn("   - Completed with $warnCount warnings");
        }
        $elapsed = round(microtime(true) - $start, 2);

        $count = $conn->table($table)->count();
        $this->info('   - Imported ' . number_format($count, 0, '', ',') . ' records in ' . $elapsed . ' seconds (bulk)');
    }

    protected function streamLoad($conn, string $table, string $file): void
    {
        // This is a massive drain on memory and performance for large files
        // configure system for large imports.
        $conn->disableQueryLog();
        ini_set('memory_limit', '2G');
        gc_enable();

        $handle = fopen($file, 'r');
        if (!$handle) {
            $this->error('Unable to open file: ' . $file);
            return;
        }

        $headers = fgetcsv($handle, 0, "\t");
        $batch = [];
        $inserted = 0;
        $batchSize = 5000;

        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($row) !== count($headers)) {
                // Skipping malformed line
                continue;
            }

            $batch[] = array_combine($headers, $row);

            if (count($batch) >= $batchSize) {
                $conn->table($table)->insert($batch);
                $inserted += count($batch);
                $this->output->write("\r" . '   - Imported ' . number_format($inserted, 0, '', ',') . ' records so far...');
                unset($batch);
                $batch = [];
                gc_collect_cycles();
            }
        }

        if ($batch) {
            $conn->table($table)->insert($batch);
            $inserted += count($batch);
            $this->output->write("\r" . '   - Imported ' . number_format($inserted, 0, '', ',') . ' records so far...');
        }

        fclose($handle);
        $this->output->write("\r" . '   - Imported ' . number_format($inserted, 0, '', ',') . ' records');
    }

    protected function cleanFile(string $file): string
    {
        $physicalTmp = tempnam(sys_get_temp_dir(), 'omop_');
        $out = fopen($physicalTmp, 'w');
        $in  = fopen($file, 'r');

        if (!$in || !$out) {
            throw new \RuntimeException('Cannot open files');
        }

        $rowNum = 0;
        $expectedCols = null;
        $delimiter = "\t";

        while (($raw = fgets($in)) !== false) {
            $line = trim($raw, "\r\n");

            if ($line === '') continue;

            // --- 1. Strip outer wrapping quotes if the whole line is quoted ---
            if ($line[0] === '"' && substr($line, -1) === '"') {
                $line = substr($line, 1, -1);
            }

            // --- 2. Collapse doubled quotes into single quotes ---
            $line = str_replace('""', '"', $line);

            // --- 3. Now safely split on tabs ---
            $fields = explode("\t", $line);
            $fields = array_map('trim', $fields);

            if ($expectedCols === null) {
                $expectedCols = count($fields);
            } elseif (count($fields) !== $expectedCols) {
                // pad/trim
                $diff = $expectedCols - count($fields);
                $fields = $diff > 0
                    ? array_pad($fields, $expectedCols, '')
                    : array_slice($fields, 0, $expectedCols);
            }

            fwrite($out, implode("\t", $fields) . PHP_EOL);
            $this->output->write("\r" . '    - Cleaned ' . number_format($rowNum, 0, '', ',') . ' lines');
            $rowNum++;
        }

        fclose($in);
        fclose($out);

        $this->output->write("\r" . '    - Finished cleaning ' . number_format($rowNum, 0, '', ',') . " lines > {$physicalTmp}");
        return $physicalTmp;
    }



    protected function createSchema()
    {
        $this->info("Creating OMOP tables...");

        $schema = Schema::connection('omop');

        if (!$schema->hasTable('vocabulary')) {
            $this->info("Creating 'vocabulary' table...");
            $schema->create('vocabulary', function (Blueprint $table) {
                $table->string('vocabulary_id', 128);
                $table->string('vocabulary_name', 255);
                $table->string('vocabulary_reference', 255)->nullable();
                $table->string('vocabulary_version', 255)->nullable();
                $table->string('vocabulary_concept_id', 50);

                $table->index('vocabulary_id');
                $table->index('vocabulary_concept_id');
                $table->index('vocabulary_name');
            });
        }

        // domain
        if (! $schema->hasTable('domain')) {
            $this->info("Creating 'domain' table...");
            $schema->create('domain', function (Blueprint $table) {
                $table->string('domain_id', 50);
                $table->string('domain_name', 255);
                $table->string('domain_concept_id', 50)->nullable();

                $table->index('domain_id');
                $table->index('domain_concept_id');
                $table->index('domain_name');
            });
        }

        // concept_class
        if (! $schema->hasTable('concept_class')) {
            $this->info("Creating 'concept_class' table...");
            $schema->create('concept_class', function (Blueprint $table) {
                $table->string('concept_class_id', 50);
                $table->string('concept_class_name', 255);
                $table->bigInteger('concept_class_concept_id')->nullable();

                $table->index('concept_class_id');
                $table->index('concept_class_concept_id');
            });
        }

        // relationship
        if (! $schema->hasTable('relationship')) {
            $this->info("Creating 'relationship' table...");
            $schema->create('relationship', function (Blueprint $table) {
                $table->string('relationship_id', 50)->nullable();
                $table->string('relationship_name', 255);
                $table->integer('is_hierarchical')->default(0);
                $table->integer('defines_ancestry')->default(0);
                $table->string('reverse_relationship_id', 50)->nullable();
                $table->bigInteger('relationship_concept_id')->nullable();

                $table->index('relationship_id');
                $table->index('relationship_concept_id');
                $table->index('relationship_name');
                $table->index('reverse_relationship_id');
            });
        }

        // concept
        if (! $schema->hasTable('concept')) {
            $this->info("Creating 'concept' table...");
            $schema->create('concept', function (Blueprint $table) {
                $table->unsignedBigInteger('concept_id');
                $table->string('concept_name', 255);
                $table->string('domain_id', 50);
                $table->string('vocabulary_id', 50);
                $table->string('concept_class_id');
                $table->string('standard_concept', 1)->nullable();
                $table->string('concept_code', 50);
                $table->string('valid_start_date', 10);
                $table->string('valid_end_date', 10);
                $table->char('invalid_reason', 1)->nullable();

                $table->index('domain_id');
                $table->index('concept_class_id');
                $table->index('vocabulary_id');
                $table->index('concept_code');
            });
        }

        // concept_relationship
        if (! $schema->hasTable('concept_relationship')) {
            $this->info("Creating 'concept_relationship' table...");
            $schema->create('concept_relationship', function (Blueprint $table) {
                $table->unsignedBigInteger('concept_id_1');
                $table->unsignedBigInteger('concept_id_2');
                $table->string('relationship_id', 50);
                $table->date('valid_start_date')->nullable();
                $table->date('valid_end_date')->nullable();
                $table->string('invalid_reason', 50)->nullable();

                $table->index('concept_id_1');
                $table->index('concept_id_2');
                $table->index('relationship_id');
            });
        }

        // concept_synonym
        if (! $schema->hasTable('concept_synonym')) {
            $this->info("Creating 'concept_synonym' table...");
            $schema->create('concept_synonym', function (Blueprint $table) {
                $table->unsignedBigInteger('concept_id');
                $table->string('concept_synonym_name', 255);
                $table->unsignedBigInteger('language_concept_id');

                $table->index('concept_id');
                $table->index('language_concept_id');
            });
        }

        // concept_ancestor
        if (! $schema->hasTable('concept_ancestor')) {
            $this->info("Creating 'concept_ancestor' table...");
            $schema->create('concept_ancestor', function (Blueprint $table) {
                $table->unsignedBigInteger('ancestor_concept_id');
                $table->unsignedBigInteger('descendant_concept_id');
                $table->integer('min_levels_of_separation')->default(0);
                $table->integer('max_levels_of_separation')->default(0);

                $table->index('descendant_concept_id');
                $table->index('ancestor_concept_id');
            });
        }

        // drug_strength
        if (! $schema->hasTable('drug_strength')) {
            $this->info("Creating 'drug_strength' table...");
            $schema->create('drug_strength', function (Blueprint $table) {
                $table->unsignedBigInteger('drug_concept_id');
                $table->unsignedBigInteger('ingredient_concept_id');
                $table->decimal('amount_value', 10, 4)->nullable();
                $table->string('amount_unit_concept_id', 50)->nullable();
                $table->decimal('numerator_value', 10, 4)->nullable();
                $table->string('numerator_unit_concept_id', 50)->nullable();
                $table->decimal('denominator_value', 10, 4)->nullable();

                $table->index('drug_concept_id');
                $table->index('ingredient_concept_id');
            });
        }

        $this->info("OMOP vocabulary tables created successfully.");
    }
}
