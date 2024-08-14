<?php

namespace Omid\Eloquent;

use PDO;
use PDOException;

class NDB
{
    private static $instance = null;
    protected static string $table = '';
    private static string $host = 'localhost';
    private static string $dbName = 'bh';
    private static string $username = 'root';
    private static string $password = 'password';
    private static PDO $PDO;

    public function __construct()
    {
        try {
            self::$PDO = new PDO("mysql:host=" . self::$host . ";dbname=" . self::$dbName, self::$username, self::$password);
            // set the PDO error mode to exception
            self::$PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return;
//            echo "Connected successfully";
        } catch (PDOException $e) {
            echo "Connection failed: " . $e->getMessage();
            exit();
        }

        self::$instance = new self();
    }

}