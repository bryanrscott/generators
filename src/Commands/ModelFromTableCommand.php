<?php

namespace Laracademy\Generators\Commands;

use DB;
use Illuminate\Console\Command;
use Schema;

class ModelFromTableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:modelfromtable
                            {--table= : a single table or a list of tables separated by a comma (,)}
                            {--schema= : the default schema to use for processing }
                            {--connection= : database connection to use, leave off and it will use the .env connection}
                            {--debug : turns on debugging}
                            {--folder= : by default models are stored in app, but you can change that}
                            {--namespace= : by default the namespace that will be applied to all models is App}
                            {--all : run for all tables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate models for the given tables based on their columns';

    public $fieldsFillable;
    public $fieldsHidden;
    public $fieldsCast;
    public $fieldsDate;
    public $columns;

    public $debug;
    public $options;

    public $databaseConnection;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->options = [
            'connection' => '',
            'table'      => '',
            'schema'     => '',
            'folder'     => app_path(),
            'debug'      => false,
            'all'        => false,
        ];
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->doComment('Starting Model Generate Command', true);
        $this->getOptions();

        // Empty schema does not work with sql information tables.  set to sensible default
        if ($this->options['schema'] === '') {
            if ($this->getDriverName() === 'pgsql') {
                $this->options['schema'] = 'public';
            } else {
                $this->options['schema'] = $this->getDatabaseName();
            }
            $this->options['defaultschema'] = $this->options['schema'];
        }

        $tables = [];
        $path = $this->options['folder'];
        $modelStub = file_get_contents($this->getStub());

        // can we run?
        if (strlen($this->options['table']) <= 0 && $this->options['all'] == false) {
            $this->error('No --table specified or --all');

            return;
        }

        // figure out if we need to create a folder or not
        if ($this->options['folder'] != app_path()) {
            if (!is_dir($this->options['folder'])) {
                mkdir($this->options['folder']);
            }
        }

        // figure out if it is all tables
        if ($this->options['all']) {
            $tables = $this->getAllTables($this->options['schema']);
        } else {
            $tables = explode(',', $this->options['table']);
        }

        // cycle through each table
        foreach ($tables as $table) {
            // grab a fresh copy of our stub
            $stub = $modelStub;

            // generate the file name for the model based on the table name
            $filename = str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $table)));
            $fullPath = "$path/$filename.php";
            $this->doComment("Generating file: $filename.php $table", true);

            // gather information on it
            // getColumnListing doesn't seem to work on pgsql so modified.
            $model = [
                'table'     => $table,
                'fillable'  => [],
                'guardable' => [],
                'hidden'    => [],
                'casts'     => [],
            ];
            // fix these up
            $columns = $this->describeTable($table);

            // use a collection
            $this->columns = collect();

            foreach ($columns as $col) {
                if (isset($col->column_name)) {
                    $this->columns->push([
                        'field' => $col->column_name,
                        'type'  => $col->data_type,
                    ]);
                } elseif (isset($col->Field)) {
                    $this->columns->push([
                        'field' => $col->Field,
                        'type'  => $col->Type,
                    ]);
                } else {
                    $this->doComment('Unknown column format', true);
                }
            }
            foreach ($this->columns as $col) {
                $model['fillable'][] = $col['field'];
            }

            // replace the class name
            $stub = $this->replaceClassName($stub, $filename);

            // replace the fillable
            $stub = $this->replaceModuleInformation($stub, $model);

            // figure out the connection
            $stub = $this->replaceConnection($stub, $this->options['connection']);

            // writing stub out
            $this->doComment('Writing model: '.$fullPath, true);
            file_put_contents($fullPath, $stub);
        }

        $this->info('Complete');
    }

    public function getColumnListing($tableName)
    {
        $this->doComment('Retrieving table definition for: '.$tableName, true);
        $value = null;
        if (strlen($this->options['connection']) <= 0) {
            $value = Schema::getColumnListing($tableName);
        } else {
            $value = Schema::connection($this->options['connection'])->getColumnListing($tableName);
        }

        return $value;
    }

    public function getDatabaseName()
    {
        if (strlen($this->options['connection']) <= 0) {
            return Schema::getConnection()->getDatabaseName();
        } else {
            return Schema::connection($this->options['connection'])->getDriverName();
        }
    }

    public function getDriverName()
    {
        if (strlen($this->options['connection']) <= 0) {
            return Schema::getConnection()->getDriverName();
        } else {
            return Schema::connection($this->options['connection'])->getDriverName();
        }
    }

    public function describeTable($tableName)
    {
        $this->doComment('Retrieving column information for : '.$tableName, true);
        $tableSchema = $this->options['schema'];
        if (strpos($tableName, '.')) {
            $tableSchema = substr($tableName, 0, strpos($tableName, '.'));
            $tableName = substr($tableName, strpos($tableName, '.') + 1);
        }
        $sql = "SELECT * FROM information_schema.columns WHERE table_schema = '".$tableSchema."' and table_name ='".$tableName."'";
        $this->doComment($sql, true);
        if (strlen($this->options['connection']) <= 0) {
            return DB::select(DB::raw($sql));
        } else {
            return DB::connection($this->options['connection'])->select(DB::raw($sql));
        }
    }

    /**
     * replaces the class name in the stub.
     *
     * @param string $stub      stub content
     * @param string $tableName the name of the table to make as the class
     *
     * @return string stub content
     */
    public function replaceClassName($stub, $tableName)
    {
        return str_replace('{{class}}', str_singular(ucfirst($tableName)), $stub);
    }

    /**
     * replaces the module information.
     *
     * @param string $stub             stub content
     * @param array  $modelInformation array (key => value)
     *
     * @return string stub content
     */
    public function replaceModuleInformation($stub, $modelInformation)
    {
        // replace table
        $stub = str_replace('{{table}}', $modelInformation['table'], $stub);

        // replace fillable
        $this->fieldsHidden = '';
        $this->fieldsFillable = '';
        $this->fieldsCast = '';
        $this->timestamps = false;
        foreach ($modelInformation['fillable'] as $field) {
            $this->doComment('Checking field : '.$field, true);
            // fillable and hidden
            if ($field == 'created_at' || $field == 'updated_at') {
                $this->timestamps = true;
            } elseif ($field == 'id') {
            } else {
                $this->fieldsFillable .= (strlen($this->fieldsFillable) > 0 ? ', ' : '')."'$field'";

                $fieldsFiltered = $this->columns->where('field', $field);
                if ($fieldsFiltered) {
                    // check type
                    switch (strtolower($fieldsFiltered->first()['type'])) {
                        case 'timestamp':
                            $this->fieldsDate .= (strlen($this->fieldsDate) > 0 ? ', ' : '')."'$field'";
                            break;
                        case 'datetime':
                            $this->fieldsDate .= (strlen($this->fieldsDate) > 0 ? ', ' : '')."'$field'";
                            break;
                        case 'date':
                            $this->fieldsDate .= (strlen($this->fieldsDate) > 0 ? ', ' : '')."'$field'";
                            break;
                        case 'tinyint(1)':
                            $this->fieldsCast .= (strlen($this->fieldsCast) > 0 ? ', ' : '')."'$field' => 'boolean'";
                            break;
                    }
                }
//            } else {
//                $this->fieldsHidden .= (strlen($this->fieldsHidden) > 0 ? ', ' : '')."'$field'";
            }
        }

        // replace in stub
        $stub = str_replace('{{fillable}}', $this->fieldsFillable, $stub);
        $stub = str_replace('{{hidden}}', $this->fieldsHidden, $stub);
        $stub = str_replace('{{casts}}', $this->fieldsCast, $stub);
        $stub = str_replace('{{dates}}', $this->fieldsDate, $stub);
        $stub = str_replace('{{modelnamespace}}', $this->options['namespace'], $stub);
        $stub = str_replace('{{timestamps}}', ($this->timestamps ? '' : 'public $timestamps = false;'), $stub);

        return $stub;
    }

    public function replaceConnection($stub, $database)
    {
        $replacementString = '/**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = \''.$database.'\'';

        if (strlen($database) <= 0) {
            $stub = str_replace('{{connection}}', '', $stub);
        } else {
            $stub = str_replace('{{connection}}', $replacementString, $stub);
        }

        return $stub;
    }

    /**
     * returns the stub to use to generate the class.
     */
    public function getStub()
    {
        $this->doComment('loading model stub');

        return __DIR__.'/../stubs/model.stub';
    }

    /**
     * returns all the options that the user specified.
     */
    public function getOptions()
    {
        // debug
        $this->options['debug'] = ($this->option('debug')) ? true : false;

        // connection
        $this->options['connection'] = ($this->option('connection')) ? $this->option('connection') : '';

        // folder
        $this->options['folder'] = ($this->option('folder')) ? base_path($this->option('folder')) : app_path();
        // trim trailing slashes
        $this->options['folder'] = rtrim($this->options['folder'], '/');

        // namespace
        $this->options['namespace'] = ($this->option('namespace')) ? str_replace('app', 'App', $this->option('namespace')) : 'App';
        // remove trailing slash if exists
        $this->options['namespace'] = rtrim($this->options['namespace'], '/');
        // fix slashes
        $this->options['namespace'] = str_replace('/', '\\', $this->options['namespace']);

        // all tables
        $this->options['all'] = ($this->option('all')) ? true : false;

        // single or list of tables
        $this->options['table'] = ($this->option('table')) ? $this->option('table') : '';

        // single or list of default schema
        $this->options['schema'] = ($this->option('schema')) ? $this->option('schema') : '';
    }

    /**
     * will add a comment to the screen if debug is on, or is over-ridden.
     */
    public function doComment($text, $overrideDebug = false)
    {
        if ($this->options['debug'] || $overrideDebug) {
            $this->comment($text);
        }
    }

    /**
     * will return an array of all table names.
     */
    public function getAllTables($schema = 'public')
    {
        if ($schema === '') {
            return [];
        }

        $tables = [];
        if (isset($this->options['defaultschema'])) {
            $sql = "SELECT distinct table_name FROM information_schema.columns WHERE table_schema = '".$schema."'";
        } else {
            $sql = "SELECT distinct CONCAT('".$schema.".',table_name) as table_name FROM information_schema.columns WHERE table_schema = '".$schema."'";
        }
        if (strlen($this->options['connection']) <= 0) {
            $tables = collect(DB::select(DB::raw($sql)))->flatten();
        } else {
            $tables = collect(DB::connection($this->options['connection'])->select(DB::raw($sql)))->flatten();
        }

        $tables = $tables->map(function ($value, $key) {
            return collect($value)->flatten()[0];
        })->reject(function ($value, $key) {
            return $value == 'migrations';
        });

        return $tables;
    }
}
