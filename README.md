# PHP Database Class

A lightweight and versatile PHP database class that supports both MySQL and SQLite, providing an easy-to-use interface for common database operations such as `SELECT`, `INSERT`, `UPDATE`, and `DELETE`.

## Features

- **Multiple Database Support**: Easily switch between MySQL and SQLite by changing the configuration.
- **Automatic Table Naming**: Automatically determines the table name based on the class name, with an option to override it.
- **Flexible Query Builder**: Chain methods for `WHERE`, `JOIN`, `ORDER BY`, `LIMIT`, and more.
- **CRUD Operations**: Simplified methods for creating, reading, updating, and deleting records.
- **Aggregation Functions**: Includes `MIN`, `MAX`, and `COUNT` functions for efficient data retrieval.
- **File Creation for SQLite**: Automatically creates the SQLite database file if it doesn't exist.

## Installation

1. Clone this repository to your project:
    ```bash
    git clone https://github.com/OR81/eloquent.git
    ```

2. Include the `DB` class in your project:
    ```php
    require_once 'path/to/DB.php';
    ```

3. Configure the class for your database:
    - **MySQL** (default):
      ```php
      $db = new \Config\DB();
      ```
    - **SQLite**:
      ```php
      $db = new \Config\DB();
      $db->driver = 'sqlite';
      $db->sqlitePath = 'path/to/your/database.sqlite';
      ```

## Usage

### Selecting Data

- **Basic Select**:
  ```php
  $results = $db->table('users')->select('*')->get();
  ```

- **With Where Clause**:
  ```php
  $results = $db->table('users')->where('age', '>', 25)->get();
  ```

- **With Join**:
  ```php
  $results = $db->table('users')
                ->join('posts', 'users.id', 'posts.user_id')
                ->select('users.name, posts.title')
                ->get();
  ```

- **Aggregation**:
  ```php
  $maxAge = $db->table('users')->max('age');
  ```

### Inserting Data

- **Single Insert**:
  ```php
  $user = $db->table('users')->insert([
      'name' => 'John Doe',
      'email' => 'john@example.com'
  ]);
  ```

- **Multiple Inserts**:
  ```php
  $db->table('users')->insertMultiple([
      ['name' => 'Alice', 'email' => 'alice@example.com'],
      ['name' => 'Bob', 'email' => 'bob@example.com']
  ]);
  ```

### Updating Data

- **Update Record**:
  ```php
  $db->table('users')
     ->where('id', 1)
     ->update(['name' => 'John Smith']);
  ```

### Deleting Data

- **Delete Record**:
  ```php
  $db->table('users')->where('id', 1)->delete();
  ```

### Counting Records

- **Count All Records**:
  ```php
  $userCount = $db->table('users')->count();
  ```

## Configuration

The `DB` class can be configured by modifying the following properties:

- **MySQL Configuration**:
    - `$driver = 'mysql';` (default)
    - `$host = 'localhost';`
    - `$dbName = 'your_database';`
    - `$username = 'your_username';`
    - `$password = 'your_password';`

- **SQLite Configuration**:
    - `$driver = 'sqlite';`
    - `$sqlitePath = 'path/to/your/database.sqlite';`

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for more details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request or open an Issue on GitHub.

## Contact

For any inquiries or support, please reach out to [omidrajabi81@gmail.com](mailto:omidrajabi81@gmail.com).