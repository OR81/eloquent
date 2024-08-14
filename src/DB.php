<?php

namespace Omid\Eloquent;

use JetBrains\PhpStorm\NoReturn;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;

#[NoReturn] function dd($parameter): void
{
    print_r($parameter);
    exit();
}

class DB
{
    protected string $table = '';
    private string $driver = 'mysql'; // Default to MySQL. Change to 'sqlite' to use SQLite.
    private string $host = 'localhost';
    private string $dbName = 'database';
    private string $username = 'root';
    private string $password = 'password';
    private string $sqlitePath = 'database.sqlite'; // Path to the SQLite file.
    private PDO $PDO;
    private string $query = '';
    private string $where = '';
    private string $join = '';
    private string $orderBy = '';
    private string $limit = '';

    /**
     * @throws \ReflectionException
     */
    public function resetVariables(): void
    {
        $exclude = ['PDO'];

        $reflection = new ReflectionClass($this::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE);

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            if (!in_array($propertyName, $exclude)) {
                $property->setValue($this, $property->getDefaultValue());
            }
        }
    }

    public function select(string $columns_name = '*'): DB
    {
        $this->tableCheck();
        $this->query = "SELECT $columns_name FROM " . $this->table;
        return $this;
    }

    public function min(string $column_name): DB
    {
        return $this->aggregate('MIN', $column_name);
    }

    public function max(string $column_name): DB
    {
        return $this->aggregate('MAX', $column_name);
    }

    private function aggregate(string $function, string $column_name): DB
    {
        $this->tableCheck();
        $this->query = "SELECT $function($column_name) as $column_name FROM " . $this->table;
        return $this;
    }

    public function table(string $table = ''): DB
    {
        if ($table == '') {
            $t = explode('\\', $this::class);
            $t = $t[count($t) - 1];
            $t = str_replace('_model', '', $t);
            $table = $this->pluralize($t);
        }

        $this->table = $table;
        return $this;
    }

    private function tableCheck(): void
    {
        if (empty($this->table)) {
            $this->table();
        }
    }

    private function pluralize(string $string): string
    {
        $lastChar = substr($string, -1);
        $secondLastChar = substr($string, -2, 1);

        if ($lastChar === 'y' && !in_array($secondLastChar, ['a', 'e', 'i', 'o', 'u'])) {
            return substr($string, 0, -1) . 'ies';
        } elseif (in_array($lastChar, ['s', 'x', 'z']) || $secondLastChar . $lastChar === 'ch' || $secondLastChar . $lastChar === 'sh') {
            return $string . 'es';
        }

        return $string . 's';
    }

    public function where(string|array $firstParameter, string $secondParameter = '', string $thirdParameter = ''): DB
    {
        $conjunction = empty($this->where) ? 'WHERE' : 'AND';
        $this->whereCore($firstParameter, $secondParameter, $thirdParameter, $conjunction);
        return $this;
    }

    private function whereCore(string|array $firstParameter, string $secondParameter, string $thirdParameter, string $conjunction): void
    {
        if (is_array($firstParameter)) {
            foreach ($firstParameter as $index => $item) {
                $this->where($index, $item);
            }
        } else {
            if (empty($thirdParameter)) {
                $thirdParameter = $secondParameter;
                $secondParameter = '=';
            }
            $this->where .= " $conjunction $firstParameter $secondParameter '$thirdParameter'";
        }
    }

    public function orWhere(string $firstParameter, string $secondParameter = '', string $thirdParameter = ''): DB
    {
        $conjunction = empty($this->where) ? 'WHERE' : 'OR';
        $this->whereCore($firstParameter, $secondParameter, $thirdParameter, $conjunction);
        return $this;
    }

    public function whereNot(string $firstParameter, string $secondParameter = '', string $thirdParameter = ''): DB
    {
        $conjunction = empty($this->where) ? 'WHERE NOT' : 'AND NOT';
        $this->whereCore($firstParameter, $secondParameter, $thirdParameter, $conjunction);
        return $this;
    }

    public function whereNull(string $firstParameter): DB
    {
        $conjunction = empty($this->where) ? 'WHERE' : 'AND';
        $this->whereCore($firstParameter, 'IS', 'NULL', $conjunction);
        return $this;
    }

    public function whereNotNull(string $firstParameter): DB
    {
        $conjunction = empty($this->where) ? 'WHERE' : 'AND';
        $this->whereCore($firstParameter, 'IS NOT', 'NULL', $conjunction);
        return $this;
    }

    public function in(string $firstParameter, array $secondParameter): DB
    {
        $string = '(' . implode(', ', array_map(fn($item) => "'$item'", $secondParameter)) . ')';
        $conjunction = empty($this->where) ? 'WHERE' : 'AND';
        $this->whereCore($firstParameter, 'IN', $string, $conjunction);
        return $this;
    }

    public function notIn(string $firstParameter, array $secondParameter): DB
    {
        $string = '(' . implode(', ', array_map(fn($item) => "'$item'", $secondParameter)) . ')';
        $conjunction = empty($this->where) ? 'WHERE' : 'AND';
        $this->whereCore($firstParameter, 'NOT IN', $string, $conjunction);
        return $this;
    }

    public function between(string $column, $value1, $value2): DB
    {
        $conjunction = empty($this->where) ? 'WHERE' : 'AND';
        $this->where .= " $conjunction $column BETWEEN '$value1' AND '$value2'";
        return $this;
    }

    public function join(string $table, string $firstColumn, string $secondColumn, string $type = 'INNER'): DB
    {
        $this->join .= " $type JOIN $table ON $firstColumn = $secondColumn";
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): DB
    {
        $this->orderBy = " ORDER BY $column $direction";
        return $this;
    }

    public function limit(int $limit, int $offset = 0): DB
    {
        $this->limit = " LIMIT $offset, $limit";
        return $this;
    }

    public function get(): array|false
    {
        $this->tableCheck();
        if (empty($this->query)) $this->select();

        $stmt = $this->prepare($this->query . $this->join . $this->where . $this->orderBy . $this->limit);

        $this->resetVariables();

        $result = $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $result ? $stmt->fetchAll() : false;
    }

    public function first(): array|false
    {
        $result = $this->limit(1)->get();
        return $result ? $result[0] : false;
    }

    public function last(): array|false
    {
        $result = $this->orderBy('id', 'DESC')->limit(1)->get();
        return $result ? $result[0] : false;
    }

    public function count(): int
    {
        $result = $this->select('COUNT(*) as count')->get();
        return $result ? (int)$result[0]['count'] : 0;
    }

    public function insert(array $data): array|false
    {
        $this->tableCheck();

        $columns = array_keys($data);
        $columnsWithComma = implode(', ', $columns);
        $columnsWithColon = ':' . implode(', :', $columns);

        $query = "INSERT INTO " . $this->table . " ($columnsWithComma) VALUES ($columnsWithColon)";

        $this->prepare($query, $columns, [$data]);

        $lastInsertId = $this->PDO->lastInsertId();
        $table = $this->table;
        $this->resetVariables();

        return $this->table($table)->where('id', $lastInsertId)->first();
    }

    public function insertMultiple(array $data): void
    {
        $this->tableCheck();

        $columns = array_keys($data[0]);
        $columnsWithComma = implode(', ', $columns);
        $columnsWithColon = ':' . implode(', :', $columns);

        $query = "INSERT INTO " . $this->table . " ($columnsWithComma) VALUES ($columnsWithColon)";

        $this->prepare($query, $columns, $data);
        $this->resetVariables();
    }

    public function update(array $data): void
    {
        $this->tableCheck();

        $columns = array_keys($data);
        $columnsWithEqual = implode(' = ?, ', $columns) . ' = ?';

        $query = "UPDATE " . $this->table . " SET $columnsWithEqual " . $this->where;

        $this->prepare($query, $columns, [$data]);
        $this->resetVariables();
    }

    public function delete(): void
    {
        $this->tableCheck();

        $query = "DELETE FROM " . $this->table . ' ' . $this->where;
        $this->prepare($query);

        $this->resetVariables();
    }

    private function prepare(string $query, array $columns = [], array $data = []): PDOStatement
    {
        $this->connect();

        $stmt = $this->PDO->prepare($query);

        if (!empty($columns)) {
            foreach ($data as $datum) {
                foreach ($columns as $column) {
                    $stmt->bindValue(':' . $column, $datum[$column]);
                }
            }
        }

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            die('SQL Error: ' . $e->getMessage());
        }

        return $stmt;
    }

    private function connect(): void
    {
        if (!isset($this->PDO)) {
            if ($this->driver === 'sqlite') {
                if (!file_exists($this->sqlitePath)) {
                    file_put_contents($this->sqlitePath, '');
                }
                $dsn = "sqlite:" . $this->sqlitePath;
            } else {
                $dsn = "mysql:host=$this->host;dbname=$this->dbName;charset=utf8";
            }

            $this->PDO = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        }
    }
}
