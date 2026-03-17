<?php
/**
 * app_db.php
 * @version 1.0.0
 * @package Database
 * 
 * نظام إدارة قاعدة البيانات
 * يدعم الاتصال بقواعد البيانات، الاستعلامات، المعاملات، والنسخ الاحتياطي
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * App_DB
 * @package Database
 * 
 * الكلاس الرئيسي للتعامل مع قاعدة البيانات
 * يستخدم نمط Singleton لضمان اتصال واحد فقط
 */
class App_DB {
    
    // ==========================================
    // خصائص Singleton
    // ==========================================
    
    /**
     * النسخة الوحيدة من الكلاس
     * @var App_DB|null
     */
    private static $instance = null;
    
    // ==========================================
    // خصائص الاتصال
    // ==========================================
    
    /**
     * كائن اتصال PDO
     * @var PDO|null
     */
    private $connection = null;
    
    /**
     * إعدادات الاتصال
     * @var array
     */
    private $config = [
        'host' => 'localhost',
        'database' => '',
        'username' => '',
        'password' => '',
        'port' => 3306,
        'charset' => 'utf8mb4',
        'driver' => 'mysql',
        'persistent' => false,
        'options' => []
    ];
    
    /**
     * آخر استعلام تم تنفيذه
     * @var string
     */
    private $last_query = '';
    
    /**
     * آخر خطأ حدث
     * @var string
     */
    private $last_error = '';
    
    /**
     * عدد الاستعلامات المنفذة
     * @var int
     */
    private $query_count = 0;
    
    /**
     * عدد المعاملات النشطة
     * @var int
     */
    private $transaction_count = 0;
    
    /**
     * حالة الاتصال
     * @var bool
     */
    private $connected = false;
    
    /**
     * وقت بدء الاتصال
     * @var float
     */
    private $connected_at = 0;
    
    /**
     * مهلة التنفيذ
     * @var int
     */
    private $timeout = 30;
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ - خاص
     * @param array $config
     */
    private function __construct(array $config = []) {
        $this->config = array_merge($this->config, $config);
        $this->setDriverOptions();
    }
    
    /**
     * منع الاستنساخ
     */
    private function __clone() {}
    
    /**
     * منع إعادة الإنشاء
     */
    private function __wakeup() {}
    
    /**
     * الحصول على نسخة من الكلاس
     * @param array $config
     * @return App_DB
     * @throws Exception
     */
    public static function getInstance(array $config = []): App_DB {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    /**
     * تعيين خيارات PDO
     */
    private function setDriverOptions(): void {
        $this->config['options'] = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => $this->config['persistent']
        ];
    }
    
    // ==========================================
    // إدارة الاتصال
    // ==========================================
    
    /**
     * الاتصال بقاعدة البيانات
     * @return PDO
     * @throws Exception
     */
    public function connect(): PDO {
        if ($this->connection !== null && $this->connected) {
            return $this->connection;
        }
        
        try {
            $dsn = $this->buildDSN();
            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $this->config['options']
            );
            
            $this->connected = true;
            $this->connected_at = microtime(true);
            
            // تعيين المهلة
            $this->connection->setAttribute(PDO::ATTR_TIMEOUT, $this->timeout);
            
            // تعيين الترميز
            if ($this->config['driver'] === 'mysql') {
                $this->connection->exec("SET NAMES '{$this->config['charset']}'");
            }
            
            return $this->connection;
            
        } catch (PDOException $e) {
            $this->connected = false;
            $this->last_error = $e->getMessage();
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * بناء DSN
     * @return string
     */
    private function buildDSN(): string {
        $driver = $this->config['driver'];
        
        switch ($driver) {
            case 'mysql':
                return "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']};charset={$this->config['charset']}";
                
            case 'pgsql':
                return "pgsql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']}";
                
            case 'sqlite':
                return "sqlite:{$this->config['database']}";
                
            case 'sqlsrv':
                return "sqlsrv:Server={$this->config['host']},{$this->config['port']};Database={$this->config['database']}";
                
            default:
                throw new Exception("Unsupported database driver: {$driver}");
        }
    }
    
    /**
     * قطع الاتصال
     * @return bool
     */
    public function disconnect(): bool {
        $this->connection = null;
        $this->connected = false;
        return true;
    }
    
    /**
     * التحقق من حالة الاتصال
     * @return bool
     */
    public function isConnected(): bool {
        return $this->connected && $this->connection !== null;
    }
    
    /**
     * اختبار الاتصال
     * @return bool
     */
    public function ping(): bool {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * إعادة الاتصال إذا لزم الأمر
     * @return bool
     */
    public function reconnectIfNeeded(): bool {
        if (!$this->ping()) {
            $this->disconnect();
            $this->connect();
        }
        return $this->isConnected();
    }
    
    // ==========================================
    // تنفيذ الاستعلامات
    // ==========================================
    
    /**
     * تنفيذ استعلام
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     * @throws Exception
     */
    public function query(string $sql, array $params = []): PDOStatement {
        $this->reconnectIfNeeded();
        
        $this->last_query = $sql;
        $start = microtime(true);
        
        try {
            $statement = $this->connection->prepare($sql);
            $statement->execute($params);
            
            $this->query_count++;
            
            // تسجيل الاستعلامات البطيئة
            $time = microtime(true) - $start;
            if ($time > 1.0) { // أكثر من ثانية
                $this->logSlowQuery($sql, $time, $params);
            }
            
            return $statement;
            
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            throw new Exception("Query failed: " . $e->getMessage() . " [SQL: $sql]");
        }
    }
    
    /**
     * جلب جميع النتائج
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function fetchAll(string $sql, array $params = []): array {
        $statement = $this->query($sql, $params);
        return $statement->fetchAll();
    }
    
    /**
     * جلب نتيجة واحدة
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function fetchOne(string $sql, array $params = []): ?array {
        $statement = $this->query($sql, $params);
        $result = $statement->fetch();
        return $result ?: null;
    }
    
    /**
     * جلب عمود واحد
     * @param string $sql
     * @param array $params
     * @param int $column
     * @return mixed
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0) {
        $statement = $this->query($sql, $params);
        return $statement->fetchColumn($column);
    }
    
    /**
     * جلب كأزواج مفتاح-قيمة
     * @param string $sql
     * @param array $params
     * @param string $key_field
     * @param string $value_field
     * @return array
     */
    public function fetchPairs(string $sql, array $params = [], string $key_field = 'id', string $value_field = 'name'): array {
        $results = $this->fetchAll($sql, $params);
        $pairs = [];
        
        foreach ($results as $row) {
            $pairs[$row[$key_field]] = $row[$value_field];
        }
        
        return $pairs;
    }
    
    /**
     * إدراج سجل
     * @param string $table
     * @param array $data
     * @return int آخر ID
     */
    public function insert(string $table, array $data): int {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
        
        $this->query($sql, $data);
        return (int)$this->connection->lastInsertId();
    }
    
    /**
     * إدراج دفعة من السجلات
     * @param string $table
     * @param array $data
     * @return array آخر IDs
     */
    public function insertBatch(string $table, array $data): array {
        if (empty($data)) {
            return [];
        }
        
        $fields = array_keys($data[0]);
        $values = [];
        $insertData = [];
        
        foreach ($data as $index => $row) {
            $rowPlaceholders = [];
            foreach ($fields as $field) {
                $placeholder = ":{$field}_{$index}";
                $rowPlaceholders[] = $placeholder;
                $insertData[$placeholder] = $row[$field] ?? null;
            }
            $values[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") VALUES " . implode(', ', $values);
        
        $this->query($sql, $insertData);
        
        // جلب آخر IDs
        $ids = [];
        $firstId = (int)$this->connection->lastInsertId();
        for ($i = 0; $i < count($data); $i++) {
            $ids[] = $firstId + $i;
        }
        
        return $ids;
    }
    
    /**
     * تحديث سجلات
     * @param string $table
     * @param array $data
     * @param array $where
     * @return int عدد السجلات المتأثرة
     */
    public function update(string $table, array $data, array $where): int {
        $setParts = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            $setParts[] = "{$field} = :set_{$field}";
            $params["set_{$field}"] = $value;
        }
        
        $whereParts = [];
        foreach ($where as $field => $value) {
            $whereParts[] = "{$field} = :where_{$field}";
            $params["where_{$field}"] = $value;
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
        
        $statement = $this->query($sql, $params);
        return $statement->rowCount();
    }
    
    /**
     * تحديث دفعة من السجلات
     * @param string $table
     * @param array $data
     * @param string $key
     * @return bool
     */
    public function updateBatch(string $table, array $data, string $key = 'id'): bool {
        if (empty($data)) {
            return true;
        }
        
        $cases = [];
        $ids = [];
        $params = [];
        
        // تحديد الحقول المراد تحديثها
        $fields = array_keys($data[0]);
        $fields = array_diff($fields, [$key]);
        
        foreach ($fields as $field) {
            $cases[$field] = "CASE";
        }
        
        // بناء CASE statements
        foreach ($data as $index => $row) {
            $id = $row[$key];
            $ids[] = $id;
            
            foreach ($fields as $field) {
                $paramName = "{$field}_{$index}";
                $cases[$field] .= " WHEN {$key} = :{$paramName}_id THEN :{$paramName}_value";
                $params["{$paramName}_id"] = $id;
                $params["{$paramName}_value"] = $row[$field];
            }
        }
        
        foreach ($fields as $field) {
            $cases[$field] .= " END";
        }
        
        // بناء الاستعلام
        $setParts = [];
        foreach ($fields as $field) {
            $setParts[] = "{$field} = {$cases[$field]}";
        }
        
        $idsList = implode(', ', array_map('intval', $ids));
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE {$key} IN ({$idsList})";
        
        $this->query($sql, $params);
        return true;
    }
    
    /**
     * حذف سجلات
     * @param string $table
     * @param array $where
     * @return int عدد السجلات المحذوفة
     */
    public function delete(string $table, array $where): int {
        $whereParts = [];
        $params = [];
        
        foreach ($where as $field => $value) {
            $whereParts[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }
        
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $whereParts);
        
        $statement = $this->query($sql, $params);
        return $statement->rowCount();
    }
    
    /**
     * حذف دفعة من السجلات
     * @param string $table
     * @param string $field
     * @param array $values
     * @return int عدد السجلات المحذوفة
     */
    public function deleteBatch(string $table, string $field, array $values): int {
        if (empty($values)) {
            return 0;
        }
        
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql = "DELETE FROM {$table} WHERE {$field} IN ({$placeholders})";
        
        $statement = $this->query($sql, $values);
        return $statement->rowCount();
    }
    
    // ==========================================
    // المعاملات
    // ==========================================
    
    /**
     * بدء معاملة
     * @return bool
     */
    public function beginTransaction(): bool {
        if (!$this->connection->inTransaction()) {
            $result = $this->connection->beginTransaction();
            $this->transaction_count = 1;
            return $result;
        }
        
        // دعم nested transactions باستخدام savepoints
        $this->transaction_count++;
        $savepoint = "SP{$this->transaction_count}";
        return $this->connection->exec("SAVEPOINT {$savepoint}") !== false;
    }
    
    /**
     * تأكيد المعاملة
     * @return bool
     */
    public function commit(): bool {
        if ($this->transaction_count === 1) {
            $this->transaction_count = 0;
            return $this->connection->commit();
        }
        
        $this->transaction_count--;
        return true;
    }
    
    /**
     * تراجع عن المعاملة
     * @return bool
     */
    public function rollback(): bool {
        if ($this->transaction_count === 1) {
            $this->transaction_count = 0;
            return $this->connection->rollBack();
        }
        
        $savepoint = "SP{$this->transaction_count}";
        $this->transaction_count--;
        return $this->connection->exec("ROLLBACK TO SAVEPOINT {$savepoint}") !== false;
    }
    
    /**
     * التحقق من وجود معاملة نشطة
     * @return bool
     */
    public function inTransaction(): bool {
        return $this->connection->inTransaction();
    }
    
    /**
     * تنفيذ دالة داخل معاملة
     * @param callable $callback
     * @return mixed
     * @throws Exception
     */
    public function transaction(callable $callback) {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    // ==========================================
    // معلومات الجداول
    // ==========================================
    
    /**
     * التحقق من وجود جدول
     * @param string $table
     * @return bool
     */
    public function tableExists(string $table): bool {
        $driver = $this->config['driver'];
        
        switch ($driver) {
            case 'mysql':
                $sql = "SHOW TABLES LIKE ?";
                break;
            case 'pgsql':
                $sql = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)";
                break;
            case 'sqlite':
                $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
                break;
            default:
                return false;
        }
        
        return (bool)$this->fetchColumn($sql, [$table]);
    }
    
    /**
     * جلب قائمة الجداول
     * @return array
     */
    public function getTables(): array {
        $driver = $this->config['driver'];
        
        switch ($driver) {
            case 'mysql':
                $sql = "SHOW TABLES";
                break;
            case 'pgsql':
                $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'";
                break;
            case 'sqlite':
                $sql = "SELECT name FROM sqlite_master WHERE type='table'";
                break;
            default:
                return [];
        }
        
        return $this->fetchColumn($sql);
    }
    
    /**
     * جلب معلومات الأعمدة
     * @param string $table
     * @return array
     */
    public function getColumns(string $table): array {
        $driver = $this->config['driver'];
        
        switch ($driver) {
            case 'mysql':
                $sql = "SHOW COLUMNS FROM {$table}";
                return $this->fetchAll($sql);
                
            case 'pgsql':
                $sql = "SELECT column_name, data_type, is_nullable 
                        FROM information_schema.columns 
                        WHERE table_name = ?";
                return $this->fetchAll($sql, [$table]);
                
            case 'sqlite':
                $sql = "PRAGMA table_info({$table})";
                return $this->fetchAll($sql);
                
            default:
                return [];
        }
    }
    
    /**
     * جلب المفتاح الأساسي للجدول
     * @param string $table
     * @return string|null
     */
    public function getPrimaryKey(string $table): ?string {
        $columns = $this->getColumns($table);
        
        foreach ($columns as $column) {
            if (isset($column['Key']) && $column['Key'] === 'PRI') {
                return $column['Field'];
            }
            if (isset($column['pk']) && $column['pk'] == 1) {
                return $column['name'];
            }
        }
        
        return null;
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * تهريب النص
     * @param string $string
     * @return string
     */
    public function escapeString(string $string): string {
        return substr($this->connection->quote($string), 1, -1);
    }
    
    /**
     * الحصول على آخر ID
     * @param string|null $sequence
     * @return int
     */
    public function lastInsertId(?string $sequence = null): int {
        return (int)$this->connection->lastInsertId($sequence);
    }
    
    /**
     * الحصول على عدد السجلات المتأثرة
     * @return int
     */
    public function affectedRows(): int {
        return $this->connection ? $this->connection->rowCount() : 0;
    }
    
    /**
     * الحصول على نسخة PDO
     * @return PDO|null
     */
    public function getPdo(): ?PDO {
        return $this->connection;
    }
    
    /**
     * الحصول على آخر استعلام
     * @return string
     */
    public function getLastQuery(): string {
        return $this->last_query;
    }
    
    /**
     * الحصول على آخر خطأ
     * @return string
     */
    public function getLastError(): string {
        return $this->last_error;
    }
    
    /**
     * الحصول على عدد الاستعلامات
     * @return int
     */
    public function getQueryCount(): int {
        return $this->query_count;
    }
    
    /**
     * الحصول على إحصائيات
     * @return array
     */
    public function getStats(): array {
        return [
            'connected' => $this->connected,
            'connected_at' => $this->connected_at,
            'uptime' => $this->connected ? microtime(true) - $this->connected_at : 0,
            'query_count' => $this->query_count,
            'transaction_count' => $this->transaction_count,
            'driver' => $this->config['driver'],
            'database' => $this->config['database'],
            'host' => $this->config['host']
        ];
    }
    
    /**
     * تسجيل استعلام بطيء
     * @param string $sql
     * @param float $time
     * @param array $params
     */
    private function logSlowQuery(string $sql, float $time, array $params = []): void {
        $log = sprintf(
            "[SLOW QUERY] Time: %.2fs\nSQL: %s\nParams: %s\n",
            $time,
            $sql,
            json_encode($params)
        );
        
        $log_file = ROOT_PATH . '/logs/slow_queries.log';
        file_put_contents($log_file, $log, FILE_APPEND);
    }
    
    // ==========================================
    // النسخ الاحتياطي
    // ==========================================
    
    /**
     * إنشاء نسخة احتياطية
     * @param string $file_path
     * @param array $tables
     * @return bool
     */
    public function backup(string $file_path, array $tables = []): bool {
        if (empty($tables)) {
            $tables = $this->getTables();
        }
        
        $backup = [];
        $backup['meta'] = [
            'created_at' => date('Y-m-d H:i:s'),
            'driver' => $this->config['driver'],
            'database' => $this->config['database'],
            'version' => '1.0'
        ];
        
        foreach ($tables as $table) {
            $backup['tables'][$table] = [
                'structure' => $this->getTableStructure($table),
                'data' => $this->fetchAll("SELECT * FROM {$table}")
            ];
        }
        
        $backup['meta']['table_count'] = count($tables);
        $backup['meta']['row_count'] = array_sum(array_map(function($t) {
            return count($t['data']);
        }, $backup['tables']));
        
        $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($file_path, $json) !== false;
    }
    
    /**
     * استعادة نسخة احتياطية
     * @param string $file_path
     * @return bool
     */
    public function restore(string $file_path): bool {
        if (!file_exists($file_path)) {
            throw new Exception("Backup file not found: {$file_path}");
        }
        
        $content = file_get_contents($file_path);
        $backup = json_decode($content, true);
        
        if (!$backup || !isset($backup['tables'])) {
            throw new Exception("Invalid backup file");
        }
        
        return $this->transaction(function() use ($backup) {
            foreach ($backup['tables'] as $table => $data) {
                // مسح الجدول
                $this->query("TRUNCATE TABLE {$table}");
                
                // إعادة إدخال البيانات
                if (!empty($data['data'])) {
                    $this->insertBatch($table, $data['data']);
                }
            }
            return true;
        });
    }
    
    /**
     * الحصول على هيكل الجدول
     * @param string $table
     * @return array
     */
    private function getTableStructure(string $table): array {
        $columns = $this->getColumns($table);
        $indexes = [];
        
        if ($this->config['driver'] === 'mysql') {
            $indexes = $this->fetchAll("SHOW INDEX FROM {$table}");
        }
        
        return [
            'columns' => $columns,
            'indexes' => $indexes
        ];
    }
    
    /**
     * تحسين الجداول
     * @param array $tables
     * @return bool
     */
    public function optimizeTables(array $tables = []): bool {
        if (empty($tables)) {
            $tables = $this->getTables();
        }
        
        if ($this->config['driver'] === 'mysql') {
            $tableList = implode(', ', $tables);
            $this->query("OPTIMIZE TABLE {$tableList}");
            return true;
        }
        
        return false;
    }
}

/**
 * Query_Builder
 * @package Database
 * 
 * بناء الاستعلامات بطريقة برمجية
 */
class Query_Builder {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var string
     */
    private $table;
    
    /**
     * @var array
     */
    private $select = ['*'];
    
    /**
     * @var array
     */
    private $joins = [];
    
    /**
     * @var array
     */
    private $wheres = [];
    
    /**
     * @var array
     */
    private $groups = [];
    
    /**
     * @var array
     */
    private $havings = [];
    
    /**
     * @var array
     */
    private $orders = [];
    
    /**
     * @var int|null
     */
    private $limit = null;
    
    /**
     * @var int|null
     */
    private $offset = null;
    
    /**
     * @var array
     */
    private $params = [];
    
    /**
     * المُنشئ
     * @param App_DB $db
     * @param string $table
     */
    public function __construct(App_DB $db, string $table) {
        $this->db = $db;
        $this->table = $table;
    }
    
    /**
     * تحديد الحقول المطلوبة
     * @param mixed $fields
     * @return self
     */
    public function select($fields = ['*']): self {
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }
        $this->select = array_map('trim', $fields);
        return $this;
    }
    
    /**
     * إضافة JOIN
     * @param string $table
     * @param string $condition
     * @param string $type
     * @return self
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self {
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'condition' => $condition
        ];
        return $this;
    }
    
    /**
     * إضافة LEFT JOIN
     * @param string $table
     * @param string $condition
     * @return self
     */
    public function leftJoin(string $table, string $condition): self {
        return $this->join($table, $condition, 'LEFT');
    }
    
    /**
     * إضافة RIGHT JOIN
     * @param string $table
     * @param string $condition
     * @return self
     */
    public function rightJoin(string $table, string $condition): self {
        return $this->join($table, $condition, 'RIGHT');
    }
    
    /**
     * إضافة شرط WHERE
     * @param string $condition
     * @param mixed $params
     * @return self
     */
    public function where(string $condition, $params = null): self {
        $this->wheres[] = ['AND', $condition];
        
        if ($params !== null) {
            if (!is_array($params)) {
                $params = [$params];
            }
            $this->params = array_merge($this->params, $params);
        }
        
        return $this;
    }
    
    /**
     * إضافة شرط OR WHERE
     * @param string $condition
     * @param mixed $params
     * @return self
     */
    public function orWhere(string $condition, $params = null): self {
        $this->wheres[] = ['OR', $condition];
        
        if ($params !== null) {
            if (!is_array($params)) {
                $params = [$params];
            }
            $this->params = array_merge($this->params, $params);
        }
        
        return $this;
    }
    
    /**
     * إضافة شرط WHERE IN
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereIn(string $field, array $values): self {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = ['AND', "{$field} IN ({$placeholders})"];
        $this->params = array_merge($this->params, $values);
        return $this;
    }
    
    /**
     * إضافة شرط WHERE BETWEEN
     * @param string $field
     * @param mixed $start
     * @param mixed $end
     * @return self
     */
    public function whereBetween(string $field, $start, $end): self {
        $this->wheres[] = ['AND', "{$field} BETWEEN ? AND ?"];
        $this->params = array_merge($this->params, [$start, $end]);
        return $this;
    }
    
    /**
     * إضافة شرط WHERE NULL
     * @param string $field
     * @return self
     */
    public function whereNull(string $field): self {
        $this->wheres[] = ['AND', "{$field} IS NULL"];
        return $this;
    }
    
    /**
     * إضافة GROUP BY
     * @param mixed $fields
     * @return self
     */
    public function groupBy($fields): self {
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }
        $this->groups = array_merge($this->groups, array_map('trim', $fields));
        return $this;
    }
    
    /**
     * إضافة HAVING
     * @param string $condition
     * @param mixed $params
     * @return self
     */
    public function having(string $condition, $params = null): self {
        $this->havings[] = $condition;
        
        if ($params !== null) {
            if (!is_array($params)) {
                $params = [$params];
            }
            $this->params = array_merge($this->params, $params);
        }
        
        return $this;
    }
    
    /**
     * إضافة ORDER BY
     * @param string $field
     * @param string $direction
     * @return self
     */
    public function orderBy(string $field, string $direction = 'ASC'): self {
        $this->orders[] = "{$field} " . strtoupper($direction);
        return $this;
    }
    
    /**
     * تحديد عدد النتائج
     * @param int $limit
     * @return self
     */
    public function limit(int $limit): self {
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * تحديد نقطة البداية
     * @param int $offset
     * @return self
     */
    public function offset(int $offset): self {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * بناء جملة SQL
     * @return string
     */
    public function toSql(): string {
        $sql = "SELECT " . implode(', ', $this->select);
        $sql .= " FROM {$this->table}";
        
        // إضافة JOINs
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['condition']}";
        }
        
        // إضافة WHERE
        if (!empty($this->wheres)) {
            $sql .= " WHERE ";
            $first = true;
            foreach ($this->wheres as $where) {
                if (!$first) {
                    $sql .= " {$where[0]} ";
                }
                $sql .= $where[1];
                $first = false;
            }
        }
        
        // إضافة GROUP BY
        if (!empty($this->groups)) {
            $sql .= " GROUP BY " . implode(', ', $this->groups);
        }
        
        // إضافة HAVING
        if (!empty($this->havings)) {
            $sql .= " HAVING " . implode(' AND ', $this->havings);
        }
        
        // إضافة ORDER BY
        if (!empty($this->orders)) {
            $sql .= " ORDER BY " . implode(', ', $this->orders);
        }
        
        // إضافة LIMIT و OFFSET
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }
    
    /**
     * تنفيذ الاستعلام وجلب النتائج
     * @return array
     */
    public function get(): array {
        return $this->db->fetchAll($this->toSql(), $this->params);
    }
    
    /**
     * جلب أول نتيجة
     * @return array|null
     */
    public function first(): ?array {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    /**
     * جلب عدد النتائج
     * @return int
     */
    public function count(): int {
        $select = $this->select;
        $this->select = ['COUNT(*) as count'];
        $result = $this->first();
        $this->select = $select;
        
        return $result ? (int)$result['count'] : 0;
    }
    
    /**
     * التحقق من وجود نتائج
     * @return bool
     */
    public function exists(): bool {
        return $this->count() > 0;
    }
    
    /**
     * إعادة تعيين الباني
     * @return self
     */
    public function reset(): self {
        $this->select = ['*'];
        $this->joins = [];
        $this->wheres = [];
        $this->groups = [];
        $this->havings = [];
        $this->orders = [];
        $this->limit = null;
        $this->offset = null;
        $this->params = [];
        
        return $this;
    }
}

/**
 * DB_Transaction
 * @package Database
 * 
 * إدارة المعاملات بطريقة آمنة
 */
class DB_Transaction {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var array
     */
    private $savepoints = [];
    
    /**
     * المُنشئ
     * @param App_DB $db
     */
    public function __construct(App_DB $db) {
        $this->db = $db;
    }
    
    /**
     * بدء المعاملة
     */
    public function begin(): void {
        $this->db->beginTransaction();
    }
    
    /**
     * إنشاء نقطة حفظ
     * @param string $name
     */
    public function savepoint(string $name): void {
        $this->db->query("SAVEPOINT {$name}");
        $this->savepoints[] = $name;
    }
    
    /**
     * التراجع إلى نقطة حفظ
     * @param string $name
     */
    public function rollbackTo(string $name): void {
        if (in_array($name, $this->savepoints)) {
            $this->db->query("ROLLBACK TO SAVEPOINT {$name}");
        }
    }
    
    /**
     * تأكيد المعاملة
     */
    public function commit(): void {
        $this->db->commit();
    }
    
    /**
     * التراجع عن المعاملة
     */
    public function rollback(): void {
        $this->db->rollback();
    }
    
    /**
     * تنفيذ دالة داخل المعاملة
     * @param callable $callback
     * @return mixed
     */
    public function run(callable $callback) {
        $this->begin();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}

/**
 * DB_Migration
 * @package Database
 * 
 * إدارة ترحيل قواعد البيانات
 */
class DB_Migration {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var string
     */
    private $migrations_table = 'migrations';
    
    /**
     * المُنشئ
     * @param App_DB $db
     */
    public function __construct(App_DB $db) {
        $this->db = $db;
        $this->ensureMigrationsTable();
    }
    
    /**
     * التأكد من وجود جدول الترحيلات
     */
    private function ensureMigrationsTable(): void {
        if (!$this->db->tableExists($this->migrations_table)) {
            $sql = "CREATE TABLE {$this->migrations_table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $this->db->query($sql);
        }
    }
    
    /**
     * إنشاء جدول
     * @param string $name
     * @param callable $callback
     * @return bool
     */
    public function createTable(string $name, callable $callback): bool {
        $blueprint = new Migration_Blueprint($name);
        $callback($blueprint);
        
        $sql = $blueprint->toCreateSql();
        return $this->db->query($sql) !== false;
    }
    
    /**
     * حذف جدول
     * @param string $name
     * @return bool
     */
    public function dropTable(string $name): bool {
        return $this->db->query("DROP TABLE IF EXISTS {$name}") !== false;
    }
    
    /**
     * إعادة تسمية جدول
     * @param string $old
     * @param string $new
     * @return bool
     */
    public function renameTable(string $old, string $new): bool {
        return $this->db->query("RENAME TABLE {$old} TO {$new}") !== false;
    }
    
    /**
     * إضافة عمود
     * @param string $table
     * @param string $name
     * @param string $type
     * @param array $options
     * @return bool
     */
    public function addColumn(string $table, string $name, string $type, array $options = []): bool {
        $sql = "ALTER TABLE {$table} ADD COLUMN {$name} {$type}";
        
        if (!empty($options)) {
            if (isset($options['nullable']) && !$options['nullable']) {
                $sql .= " NOT NULL";
            }
            if (isset($options['default'])) {
                $sql .= " DEFAULT " . $this->db->escapeString($options['default']);
            }
            if (isset($options['after'])) {
                $sql .= " AFTER {$options['after']}";
            }
        }
        
        return $this->db->query($sql) !== false;
    }
    
    /**
     * تعديل عمود
     * @param string $table
     * @param string $name
     * @param array $options
     * @return bool
     */
    public function modifyColumn(string $table, string $name, array $options = []): bool {
        $sql = "ALTER TABLE {$table} MODIFY COLUMN {$name}";
        
        if (isset($options['type'])) {
            $sql .= " {$options['type']}";
        }
        
        if (isset($options['nullable'])) {
            $sql .= $options['nullable'] ? " NULL" : " NOT NULL";
        }
        
        if (isset($options['default'])) {
            $sql .= " DEFAULT " . $this->db->escapeString($options['default']);
        }
        
        return $this->db->query($sql) !== false;
    }
    
    /**
     * حذف عمود
     * @param string $table
     * @param string $name
     * @return bool
     */
    public function dropColumn(string $table, string $name): bool {
        return $this->db->query("ALTER TABLE {$table} DROP COLUMN {$name}") !== false;
    }
    
    /**
     * إضافة فهرس
     * @param string $table
     * @param array $columns
     * @param string|null $name
     * @param string $type
     * @return bool
     */
    public function addIndex(string $table, array $columns, ?string $name = null, string $type = 'INDEX'): bool {
        $name = $name ?? $table . '_' . implode('_', $columns) . '_index';
        $columnsList = implode(', ', $columns);
        
        $sql = "ALTER TABLE {$table} ADD {$type} {$name} ({$columnsList})";
        return $this->db->query($sql) !== false;
    }
    
    /**
     * حذف فهرس
     * @param string $table
     * @param string $name
     * @return bool
     */
    public function dropIndex(string $table, string $name): bool {
        return $this->db->query("ALTER TABLE {$table} DROP INDEX {$name}") !== false;
    }
    
    /**
     * إضافة مفتاح خارجي
     * @param string $table
     * @param array $columns
     * @param string $ref_table
     * @param array $ref_columns
     * @param array $options
     * @return bool
     */
    public function addForeignKey(string $table, array $columns, string $ref_table, array $ref_columns, array $options = []): bool {
        $name = $options['name'] ?? $table . '_' . implode('_', $columns) . '_foreign';
        $columnsList = implode(', ', $columns);
        $refColumnsList = implode(', ', $ref_columns);
        
        $sql = "ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$columnsList}) REFERENCES {$ref_table} ({$refColumnsList})";
        
        if (isset($options['onDelete'])) {
            $sql .= " ON DELETE {$options['onDelete']}";
        }
        
        if (isset($options['onUpdate'])) {
            $sql .= " ON UPDATE {$options['onUpdate']}";
        }
        
        return $this->db->query($sql) !== false;
    }
    
    /**
     * حذف مفتاح خارجي
     * @param string $table
     * @param string $name
     * @return bool
     */
    public function dropForeignKey(string $table, string $name): bool {
        return $this->db->query("ALTER TABLE {$table} DROP FOREIGN KEY {$name}") !== false;
    }
}

/**
 * Migration_Blueprint
 * @package Database
 * 
 * تصميم الجدول للترحيل
 */
class Migration_Blueprint {
    
    /**
     * @var string
     */
    private $table;
    
    /**
     * @var array
     */
    private $columns = [];
    
    /**
     * @var array
     */
    private $indexes = [];
    
    /**
     * المُنشئ
     * @param string $table
     */
    public function __construct(string $table) {
        $this->table = $table;
    }
    
    /**
     * إضافة عمود ID
     */
    public function id(): void {
        $this->increments('id');
    }
    
    /**
     * إضافة عمود تزايدي
     * @param string $name
     */
    public function increments(string $name): void {
        $this->columns[] = "{$name} INT AUTO_INCREMENT PRIMARY KEY";
    }
    
    /**
     * إضافة عمود نصي
     * @param string $name
     * @param int $length
     */
    public function string(string $name, int $length = 255): void {
        $this->columns[] = "{$name} VARCHAR({$length})";
    }
    
    /**
     * إضافة عمود نص طويل
     * @param string $name
     */
    public function text(string $name): void {
        $this->columns[] = "{$name} TEXT";
    }
    
    /**
     * إضافة عمود عدد صحيح
     * @param string $name
     */
    public function integer(string $name): void {
        $this->columns[] = "{$name} INT";
    }
    
    /**
     * إضافة عمود رقم عشري
     * @param string $name
     * @param int $precision
     * @param int $scale
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): void {
        $this->columns[] = "{$name} DECIMAL({$precision},{$scale})";
    }
    
    /**
     * إضافة عمود تاريخ
     * @param string $name
     */
    public function date(string $name): void {
        $this->columns[] = "{$name} DATE";
    }
    
    /**
     * إضافة عمود وقت
     * @param string $name
     */
    public function time(string $name): void {
        $this->columns[] = "{$name} TIME";
    }
    
    /**
     * إضافة عمود تاريخ ووقت
     * @param string $name
     */
    public function datetime(string $name): void {
        $this->columns[] = "{$name} DATETIME";
    }
    
    /**
     * إضافة عمود طابع زمني
     * @param string $name
     */
    public function timestamp(string $name): void {
        $this->columns[] = "{$name} TIMESTAMP";
    }
    
    /**
     * إضافة أعمدة created_at و updated_at
     */
    public function timestamps(): void {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }
    
    /**
     * إضافة عمود منطقي
     * @param string $name
     */
    public function boolean(string $name): void {
        $this->columns[] = "{$name} BOOLEAN";
    }
    
    /**
     * إضافة عمود JSON
     * @param string $name
     */
    public function json(string $name): void {
        $this->columns[] = "{$name} JSON";
    }
    
    /**
     * جعل العمود يقبل NULL
     * @return self
     */
    public function nullable(): self {
        $last = array_pop($this->columns);
        $this->columns[] = $last . " NULL";
        return $this;
    }
    
    /**
     * تعيين قيمة افتراضية
     * @param mixed $value
     * @return self
     */
    public function default($value): self {
        $last = array_pop($this->columns);
        $escaped = is_string($value) ? "'{$value}'" : $value;
        $this->columns[] = $last . " DEFAULT {$escaped}";
        return $this;
    }
    
    /**
     * تعيين العمود كمفتاح فريد
     * @param string|null $name
     */
    public function unique(?string $name = null): void {
        $name = $name ?? $this->table . '_unique';
        $this->indexes[] = "CONSTRAINT {$name} UNIQUE";
    }
    
    /**
     * إضافة فهرس
     * @param string|null $name
     */
    public function index(?string $name = null): void {
        $name = $name ?? $this->table . '_index';
        $this->indexes[] = "INDEX {$name}";
    }
    
    /**
     * بناء SQL الإنشاء
     * @return string
     */
    public function toCreateSql(): string {
        $columns = implode(",\n    ", $this->columns);
        $indexes = !empty($this->indexes) ? ",\n    " . implode(",\n    ", $this->indexes) : '';
        
        return "CREATE TABLE {$this->table} (\n    {$columns}{$indexes}\n)";
    }
}

?>