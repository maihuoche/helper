<?php

declare(strict_types=1);

namespace Maihuoche;

use PDO;
use Exception;
use PDOException;
use PDOStatement;
use InvalidArgumentException;

class Raw {
    public $map;
    public $value;
}

class Medoo {
    public $pdo;
    public $type;
    protected $prefix;
    protected $statement;
    protected $dsn;
    protected $logs = [];
    protected $logging = false;
    protected $testMode = false;
    public $queryString;
    protected $debugMode = false;
    protected $debugLogging = false;
    protected $debugLogs = [];
    protected $guid = 0;
    public $returnId = '';
    public $error = null;
    public $errorInfo = null;

    public function __construct(array $options) {
        if (isset($options['prefix'])) {
            $this->prefix = $options['prefix'];
        }

        if (isset($options['testMode']) && $options['testMode'] == true) {
            $this->testMode = true;
            return;
        }

        if (isset($options['logging']) && is_bool($options['logging'])) {
            $this->logging = $options['logging'];
        }

        $option = $options['option'] ?? [];
        $commands = ['SET SQL_MODE=ANSI_QUOTES'];

        if (isset($options['pdo'])) {
            if (!$options['pdo'] instanceof PDO) {
                throw new InvalidArgumentException('Invalid PDO object supplied.');
            }
            $this->pdo = $options['pdo'];
            foreach ($commands as $value) {
                $this->pdo->exec($value);
            }
            return;
        }

        $attr = [
            'driver' => 'mysql',
            'dbname' => $options['database'] ?? $options['database_name']
        ];

        if (isset($options['socket'])) {
            $attr['unix_socket'] = $options['socket'];
        } else {
            $attr['host'] = $options['host'] ?? $options['server'] ?? false;
            if (isset($options['port']) && is_int($options['port'] * 1)) {
                $attr['port'] = $options['port'];
            }
        }

        $stack = [];
        foreach ($attr as $key => $value) {
            $stack[] = is_int($key) ? $value : $key . '=' . $value;
        }

        $dsn = 'mysql:' . implode(';', $stack);

        if (isset($options['charset'])) {
            $commands[] = "SET NAMES '{$options['charset']}'" . 
                (isset($options['collation']) ? " COLLATE '{$options['collation']}'" : '');
        }

        $this->dsn = $dsn;

        try {
            $this->pdo = new PDO(
                $dsn,
                $options['username'] ?? null,
                $options['password'] ?? null,
                $option
            );

            if (isset($options['error'])) {
                $this->pdo->setAttribute(
                    PDO::ATTR_ERRMODE,
                    in_array($options['error'], [
                        PDO::ERRMODE_SILENT,
                        PDO::ERRMODE_WARNING,
                        PDO::ERRMODE_EXCEPTION
                    ]) ?
                    $options['error'] :
                    PDO::ERRMODE_SILENT
                );
            }

            if (isset($options['command']) && is_array($options['command'])) {
                $commands = array_merge($commands, $options['command']);
            }

            foreach ($commands as $value) {
                $this->pdo->exec($value);
            }
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage());
        }
    }

    protected function mapKey(): string {
        return ':MeD' . $this->guid++ . '_mK';
    }

    public function query(string $statement, array $map = []): ?PDOStatement {
        $raw = $this->raw($statement, $map);
        $statement = $this->buildRaw($raw, $map);
        return $this->exec($statement, $map);
    }

    public function exec(string $statement, array $map = [], callable $callback = null): ?PDOStatement {
        $this->statement = null;
        $this->errorInfo = null;
        $this->error = null;

        if ($this->testMode) {
            $this->queryString = $this->generate($statement, $map);
            return null;
        }

        if ($this->debugMode) {
            if ($this->debugLogging) {
                $this->debugLogs[] = $this->generate($statement, $map);
                return null;
            }
            echo $this->generate($statement, $map);
            $this->debugMode = false;
            return null;
        }

        if ($this->logging) {
            $this->logs[] = [$statement, $map];
        } else {
            $this->logs = [[$statement, $map]];
        }

        $statement = $this->pdo->prepare($statement);
        $errorInfo = $this->pdo->errorInfo();

        if ($errorInfo[0] !== '00000') {
            $this->errorInfo = $errorInfo;
            $this->error = $errorInfo[2];
            return null;
        }

        foreach ($map as $key => $value) {
            $statement->bindValue($key, $value[0], $value[1]);
        }

        if (is_callable($callback)) {
            $this->pdo->beginTransaction();
            $callback($statement);
            $execute = $statement->execute();
            $this->pdo->commit();
        } else {
            $execute = $statement->execute();
        }

        $errorInfo = $statement->errorInfo();

        if ($errorInfo[0] !== '00000') {
            $this->errorInfo = $errorInfo;
            $this->error = $errorInfo[2];
            return null;
        }

        if ($execute) {
            $this->statement = $statement;
        }

        return $statement;
    }

    protected function selectContext(
        string $table,
        array &$map,
        $join,
        &$columns = null,
        $where = null
    ): string {
        preg_match('/(?<table>[\p{L}_][\p{L}\p{N}@$#\-_]*)\s*\((?<alias>[\p{L}_][\p{L}\p{N}@$#\-_]*)\)/u', $table, $tableMatch);
    
        if (isset($tableMatch['table'], $tableMatch['alias'])) {
            $table = $this->tableQuote($tableMatch['table']);
            $tableAlias = $this->tableQuote($tableMatch['alias']);
            $tableQuery = "{$table} AS {$tableAlias}";
        } else {
            $table = $this->tableQuote($table);
            $tableQuery = $table;
        }
    
        $isJoin = $this->isJoin($join);
    
        if ($isJoin) {
            $tableQuery .= ' ' . $this->buildJoin($tableAlias ?? $table, $join, $map);
        } else {
            if (is_null($columns)) {
                if (!is_null($where) || (is_array($join))) {
                    $where = $join;
                    $columns = null;
                } else {
                    $where = null;
                    $columns = $join;
                }
            } else {
                $where = $columns;
                $columns = $join;
            }
        }
    
        if (isset($columns)) {
            if ($isJoin && is_null($columns)) {
                $columns = '*';
            }
            $column = $this->columnPush($columns, $map, true, $isJoin);
        } else {
            $column = '*';
        }
    
        return 'SELECT ' . $column . ' FROM ' . $tableQuery . $this->whereClause($where, $map);
    }
    
    protected function isJoin($join): bool {
        if (!is_array($join)) {
            return false;
        }
    
        $keys = array_keys($join);
        if (isset($keys[0]) && is_string($keys[0]) && strpos($keys[0], '[') === 0) {
            return true;
        }
    
        return false;
    }
    
    protected function buildJoin(string $table, array $join, array &$map): string {
        $tableJoin = [];
        $type = [
            '>' => 'LEFT',
            '<' => 'RIGHT',
            '<>' => 'FULL',
            '><' => 'INNER'
        ];
    
        foreach ($join as $subtable => $relation) {
            preg_match('/(\[(?<join>\<\>?|\>\<?)\])?(?<table>[\p{L}_][\p{L}\p{N}@$#\-_]*)\s?(\((?<alias>[\p{L}_][\p{L}\p{N}@$#\-_]*)\))?/u', $subtable, $match);
    
            if ($match['join'] === '' || $match['table'] === '') {
                continue;
            }
    
            if (is_string($relation)) {
                $relation = 'USING ("' . $relation . '")';
            } elseif (is_array($relation)) {
                if (isset($relation[0])) {
                    $relation = 'USING ("' . implode('", "', $relation) . '")';
                } else {
                    $joins = [];
    
                    foreach ($relation as $key => $value) {
                        if ($key === 'AND' && is_array($value)) {
                            $joins[] = $this->dataImplode($value, $map, ' AND');
                            continue;
                        }
    
                        $joins[] = (
                            strpos($key, '.') > 0 ?
                                $this->columnQuote($key) :
                                $table . '.' . $this->columnQuote($key)
                        ) .
                        ' = ' .
                        $this->tableQuote($match['alias'] ?? $match['table']) . '.' . $this->columnQuote($value);
                    }
    
                    $relation = 'ON ' . implode(' AND ', $joins);
                }
            }
    
            $tableName = $this->tableQuote($match['table']);
            if (isset($match['alias'])) {
                $tableName .= ' AS ' . $this->tableQuote($match['alias']);
            }
    
            $tableJoin[] = $type[$match['join']] . " JOIN {$tableName} {$relation}";
        }
    
        return implode(' ', $tableJoin);
    }
    
    protected function generate(string $statement, array $map): string {
        $statement = preg_replace(
            '/(?!\'[^\s]+\s?)"([\p{L}_][\p{L}\p{N}@$#\-_]*)"(?!\s?[^\s]+\')/u',
            '`$1`',
            $statement
        );

        foreach ($map as $key => $value) {
            if ($value[1] === PDO::PARAM_STR) {
                $replace = $this->quote("{$value[0]}");
            } elseif ($value[1] === PDO::PARAM_NULL) {
                $replace = 'NULL';
            } elseif ($value[1] === PDO::PARAM_LOB) {
                $replace = '{LOB_DATA}';
            } else {
                $replace = $value[0] . '';
            }
            $statement = str_replace($key, $replace, $statement);
        }
        return $statement;
    }

    public static function raw(string $string, array $map = []): Raw {
        $raw = new Raw();
        $raw->map = $map;
        $raw->value = $string;
        return $raw;
    }

    protected function isRaw($object): bool {
        return $object instanceof Raw;
    }

    protected function buildRaw($raw, array &$map): ?string {
        if (!$this->isRaw($raw)) {
            return null;
        }

        $query = preg_replace_callback(
            '/(([`\'])[\<]*?)?((FROM|TABLE|INTO|UPDATE|JOIN|TABLE IF EXISTS)\s*)?\<(([\p{L}_][\p{L}\p{N}@$#\-_]*)(\.[\p{L}_][\p{L}\p{N}@$#\-_]*)?)\>([^,]*?\2)?/',
            function ($matches) {
                if (!empty($matches[2]) && isset($matches[8])) {
                    return $matches[0];
                }
                if (!empty($matches[4])) {
                    return $matches[1] . $matches[4] . ' ' . $this->tableQuote($matches[5]);
                }
                return $matches[1] . $this->columnQuote($matches[5]);
            },
            $raw->value
        );

        $rawMap = $raw->map;
        if (!empty($rawMap)) {
            foreach ($rawMap as $key => $value) {
                $map[$key] = $this->typeMap($value, gettype($value));
            }
        }
        return $query;
    }

    public function quote(string $string): string {
        return "'" . preg_replace('/\'/', '\'\'', $string) . "'";
    }

    public function tableQuote(string $table): string {
        if (preg_match('/^[\p{L}_][\p{L}\p{N}@$#\-_]*$/u', $table)) {
            return '"' . $this->prefix . $table . '"';
        }
        throw new InvalidArgumentException("Incorrect table name: {$table}.");
    }

    public function columnQuote(string $column): string {
        if (preg_match('/^[\p{L}_][\p{L}\p{N}@$#\-_]*(\.?[\p{L}_][\p{L}\p{N}@$#\-_]*)?$/u', $column)) {
            return strpos($column, '.') !== false ?
                '"' . $this->prefix . str_replace('.', '"."', $column) . '"' :
                '"' . $column . '"';
        }
        throw new InvalidArgumentException("Incorrect column name: {$column}.");
    }

    protected function typeMap($value, string $type): array {
        $map = [
            'NULL' => PDO::PARAM_NULL,
            'integer' => PDO::PARAM_INT,
            'double' => PDO::PARAM_STR,
            'boolean' => PDO::PARAM_BOOL,
            'string' => PDO::PARAM_STR,
            'object' => PDO::PARAM_STR,
            'resource' => PDO::PARAM_LOB
        ];

        if ($type === 'boolean') {
            $value = ($value ? '1' : '0');
        } elseif ($type === 'NULL') {
            $value = null;
        }

        return [$value, $map[$type]];
    }

    protected function columnPush(&$columns, array &$map, bool $root, bool $isJoin = false): string {
        if ($columns === '*') {
            return $columns;
        }

        $stack = [];
        $hasDistinct = false;

        if (is_string($columns)) {
            $columns = [$columns];
        }

        foreach ($columns as $key => $value) {
            $isIntKey = is_int($key);
            $isArrayValue = is_array($value);

            if (!$isIntKey && $isArrayValue && $root && count(array_keys($columns)) === 1) {
                $stack[] = $this->columnQuote($key);
                $stack[] = $this->columnPush($value, $map, false, $isJoin);
            } elseif ($isArrayValue) {
                $stack[] = $this->columnPush($value, $map, false, $isJoin);
            } elseif (!$isIntKey && $raw = $this->buildRaw($value, $map)) {
                preg_match('/(?<column>[\p{L}_][\p{L}\p{N}@$#\-_\.]*)(\s*\[(?<type>(String|Bool|Int|Number))\])?/u', $key, $match);
                $stack[] = "{$raw} AS {$this->columnQuote($match['column'])}";
            } elseif ($isIntKey && is_string($value)) {
                if ($isJoin && strpos($value, '*') !== false) {
                    throw new InvalidArgumentException('Cannot use table.* to select all columns while joining table.');
                }

                preg_match('/(?<column>[\p{L}_][\p{L}\p{N}@$#\-_\.]*)(?:\s*\((?<alias>[\p{L}_][\p{L}\p{N}@$#\-_]*)\))?(?:\s*\[(?<type>(?:String|Bool|Int|Number))\])?/u', $value, $match);

                if (!empty($match['alias'])) {
                    $stack[] = "{$this->columnQuote($match['column'])} AS {$this->columnQuote($match['alias'])}";
                } else {
                    $stack[] = $this->columnQuote($match['column']);
                }
            }
        }

        return implode(',', $stack);
    }

    protected function dataImplode(array $data, array &$map, string $conjunctor): string {
        $stack = [];

        foreach ($data as $key => $value) {
            $type = gettype($value);

            if ($type === 'array' && preg_match("/^(AND|OR)(\s+#.*)?$/", $key, $relationMatch)) {
                $stack[] = '(' . $this->dataImplode($value, $map, ' ' . $relationMatch[1]) . ')';
                continue;
            }

            $mapKey = $this->mapKey();
            $isIndex = is_int($key);

            if ($isIndex && isset($value[4]) && in_array($value[1], ['>', '>=', '<', '<=', '=', '!='])) {
                $stack[] = "{$this->columnQuote($value[0])} {$value[1]} " . $this->columnQuote($value[4]);
                continue;
            }

            if ($value === null) {
                $stack[] = $this->columnQuote($key) . ' IS NULL';
                continue;
            }

            if (is_array($value)) {
                $values = [];
                foreach ($value as $item) {
                    $values[] = $mapKey . '_' . count($values);
                    $map[$mapKey . '_' . count($values) - 1] = $this->typeMap($item, gettype($item));
                }
                $stack[] = $this->columnQuote($key) . ' IN (' . implode(', ', $values) . ')';
                continue;
            }

            $stack[] = "{$this->columnQuote($key)} = {$mapKey}";
            $map[$mapKey] = $this->typeMap($value, $type);
        }

        return implode($conjunctor . ' ', $stack);
    }

    protected function whereClause($where, array &$map): string {
        $clause = '';

        if (is_array($where)) {
            $conditions = array_diff_key($where, array_flip(['GROUP', 'ORDER', 'HAVING', 'LIMIT', 'LIKE', 'MATCH']));

            if (!empty($conditions)) {
                $clause = ' WHERE ' . $this->dataImplode($conditions, $map, ' AND');
            }

            if (isset($where['GROUP'])) {
                $clause .= ' GROUP BY ' . $this->columnQuote($where['GROUP']);
            }

            if (isset($where['ORDER'])) {
                $clause .= ' ORDER BY ';
                if (is_array($where['ORDER'])) {
                    $stack = [];
                    foreach ($where['ORDER'] as $column => $value) {
                        if ($value === 'ASC' || $value === 'DESC') {
                            $stack[] = $this->columnQuote($column) . ' ' . $value;
                        } else {
                            $stack[] = $this->columnQuote($value);
                        }
                    }
                    $clause .= implode(',', $stack);
                } else {
                    $clause .= $this->columnQuote($where['ORDER']);
                }
            }

            if (isset($where['LIMIT'])) {
                if (is_numeric($where['LIMIT'])) {
                    $clause .= ' LIMIT ' . $where['LIMIT'];
                } else if (
                    is_array($where['LIMIT']) && 
                    is_numeric($where['LIMIT'][0]) && 
                    is_numeric($where['LIMIT'][1])
                ) {
                    $clause .= " LIMIT {$where['LIMIT'][1]} OFFSET {$where['LIMIT'][0]}";
                }
            }
        }

        return $clause;
    }

    public function select(string $table, $join, $columns = null, $where = null): ?array {
        $map = [];
        $result = [];
        $columnMap = [];

        $args = func_get_args();
        $lastArgs = $args[array_key_last($args)];
        $callback = is_callable($lastArgs) ? $lastArgs : null;

        $where = is_callable($where) ? null : $where;
        $columns = is_callable($columns) ? null : $columns;

        $column = $where === null ? $join : $columns;
        $isSingle = (is_string($column) && $column !== '*');

        $statement = $this->exec($this->selectContext($table, $map, $join, $columns, $where), $map);

        if (!$statement) {
            return null;
        }

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(string $table, array $values): ?PDOStatement {
        $stack = [];
        $columns = [];
        $fields = [];
        $map = [];

        if (!isset($values[0])) {
            $values = [$values];
        }

        foreach ($values as $data) {
            foreach ($data as $key => $value) {
                $columns[] = $key;
            }
        }

        $columns = array_unique($columns);

        foreach ($values as $data) {
            $values = [];

            foreach ($columns as $key) {
                if (!isset($data[$key])) {
                    $values[] = 'NULL';
                    continue;
                }

                $value = $data[$key];
                $type = gettype($value);

                if ($raw = $this->buildRaw($value, $map)) {
                    $values[] = $raw;
                    continue;
                }

                $mapKey = $this->mapKey();
                $values[] = $mapKey;
                $map[$mapKey] = $this->typeMap($value, $type);
            }

            $stack[] = '(' . implode(', ', $values) . ')';
        }

        foreach ($columns as $key) {
            $fields[] = $this->columnQuote($key);
        }

        return $this->exec('INSERT INTO ' . $this->tableQuote($table) . ' (' . implode(', ', $fields) . ') VALUES ' . implode(', ', $stack), $map);
    }

    public function update(string $table, array $data, $where = null): ?PDOStatement {
        $fields = [];
        $map = [];

        foreach ($data as $key => $value) {
            $column = $this->columnQuote($key);
            
            if ($raw = $this->buildRaw($value, $map)) {
                $fields[] = "{$column} = {$raw}";
                continue;
            }

            $mapKey = $this->mapKey();
            $fields[] = "{$column} = {$mapKey}";
            $map[$mapKey] = $this->typeMap($value, gettype($value));
        }

        return $this->exec('UPDATE ' . $this->tableQuote($table) . ' SET ' . implode(', ', $fields) . $this->whereClause($where, $map), $map);
    }

    public function delete(string $table, $where): ?PDOStatement {
        $map = [];
        return $this->exec('DELETE FROM ' . $this->tableQuote($table) . $this->whereClause($where, $map), $map);
    }

    public function id(): ?string {
        return $this->pdo->lastInsertId();
    }

    public function debug(): self {
        $this->debugMode = true;
        return $this;
    }

    public function error(): ?string {
        return $this->error;
    }
}