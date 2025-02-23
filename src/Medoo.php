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

    protected const TABLE_PATTERN = "[\p{L}_][\p{L}\p{N}@$#\-_]*";
    protected const COLUMN_PATTERN = "[\p{L}_][\p{L}\p{N}@$#\-_\.]*";
    protected const ALIAS_PATTERN = "[\p{L}_][\p{L}\p{N}@$#\-_]*";

    public function __construct(array $options) {
        if (isset($options['prefix'])) {
            $this->prefix = $options['prefix'];
        }

        if (isset($options['testMode']) && $options['testMode'] == true) {
            $this->testMode = true;
            return;
        }

        $options['type'] = $options['type'] ?? $options['database_type'];

        if (!isset($options['pdo'])) {
            $options['database'] = $options['database'] ?? $options['database_name'];
            if (!isset($options['socket'])) {
                $options['host'] = $options['host'] ?? $options['server'] ?? false;
            }
        }

        if (isset($options['type'])) {
            $this->type = strtolower($options['type']);
            if ($this->type === 'mariadb') {
                $this->type = 'mysql';
            }
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
            'dbname' => $options['database']
        ];

        if (isset($options['socket'])) {
            $attr['unix_socket'] = $options['socket'];
        } else {
            $attr['host'] = $options['host'];
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

    public function exec(string $statement, array $map = [], ?callable $callback = null): ?PDOStatement {
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

    protected function generate(string $statement, array $map): string {
        $statement = preg_replace(
            '/(?!\'[^\s]+\s?)"([\p{L}_][\p{L}\p{N}@$#\-_]*)"(?!\s?[^\s]+\')/u',
            '`$1`',
            $statement
        );

        foreach ($map as $key => $value) {
            if ($value[1] === PDO::PARAM_STR) {
                $replace = "'" . preg_replace(['/([\'"])/', '/(\\\\\\\")/'], ["\\\\\${1}", '\\\${1}'], $value[0]) . "'";
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
            '/(([`\'])[\<]*?)?((FROM|TABLE|TABLES LIKE|INTO|UPDATE|JOIN|TABLE IF EXISTS)\s*)?\<((' . $this::TABLE_PATTERN . ')(\.' . $this::COLUMN_PATTERN . ')?)\>([^,]*?\2)?/',
            function ($matches) {
                if (!empty($matches[2]) && isset($matches[8])) {
                    return $matches[0];
                }
                if (!empty($matches[4])) {
                    return $matches[1] . $matches[4] . ' "' . $this->prefix . $matches[5] . '"';
                }
                return $matches[1] . '"' . $matches[5] . '"';
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

    public function action(callable $actions): void {
        if (is_callable($actions)) {
            $this->pdo->beginTransaction();
            try {
                $result = $actions($this);
                if ($result === false) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->commit();
                }
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }

    public function id(?string $name = null): ?string {
        return $this->pdo->lastInsertId($name);
    }

    public function debug(): self {
        $this->debugMode = true;
        return $this;
    }

    public function beginDebug(): void {
        $this->debugMode = true;
        $this->debugLogging = true;
    }

    public function debugLog(): array {
        $this->debugMode = false;
        $this->debugLogging = false;
        return $this->debugLogs;
    }

    public function last(): ?string {
        if (empty($this->logs)) {
            return null;
        }
        $log = $this->logs[array_key_last($this->logs)];
        return $this->generate($log[0], $log[1]);
    }

    public function log(): array {
        return array_map(
            function ($log) {
                return $this->generate($log[0], $log[1]);
            },
            $this->logs
        );
    }

    public function info(): array {
        $output = [
            'server' => 'SERVER_INFO',
            'driver' => 'DRIVER_NAME',
            'client' => 'CLIENT_VERSION',
            'version' => 'SERVER_VERSION',
            'connection' => 'CONNECTION_STATUS'
        ];

        foreach ($output as $key => $value) {
            try {
                $output[$key] = $this->pdo->getAttribute(constant('PDO::ATTR_' . $value));
            } catch (PDOException $e) {
                $output[$key] = $e->getMessage();
            }
        }

        $output['dsn'] = $this->dsn;
        return $output;
    }
}