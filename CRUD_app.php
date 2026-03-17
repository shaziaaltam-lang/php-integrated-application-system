<?php
/**
 * CRUD_app.php
 * @version 1.0.0
 * @package CRUD
 * 
 * نظام CRUD المتكامل
 * يوفر عمليات إنشاء، قراءة، تحديث، حذف لجميع جداول قاعدة البيانات
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * CRUD_App
 * @package CRUD
 * 
 * الكلاس الرئيسي لنظام CRUD
 * يوفر واجهة موحدة لعمليات CRUD على أي جدول
 */
class CRUD_App {
    
    // ==========================================
    // الخصائص
    // ==========================================
    
    /**
     * @var App_DB قاعدة البيانات
     */
    protected $db;
    
    /**
     * @var string اسم الجدول
     */
    protected $table;
    
    /**
     * @var string اسم المفتاح الأساسي
     */
    protected $primary_key = 'id';
    
    /**
     * @var array الحقول المسموح بملئها
     */
    protected $fillable = [];
    
    /**
     * @var array الحقول المحمية (غير مسموح بملئها)
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];
    
    /**
     * @var array الحقول المخفية من المخرجات
     */
    protected $hidden = ['password'];
    
    /**
     * @var array تحويلات أنواع الحقول
     */
    protected $casts = [];
    
    /**
     * @var array العلاقات
     */
    protected $relationships = [];
    
    /**
     * @var array الأحداث المسجلة
     */
    protected $events = [];
    
    /**
     * @var array قواعد التحقق
     */
    protected $rules = [];
    
    /**
     * @var array رسائل الخطأ المخصصة
     */
    protected $messages = [];
    
    /**
     * @var array بيانات السجل الحالي
     */
    protected $attributes = [];
    
    /**
     * @var array التغييرات على السجل الحالي
     */
    protected $changes = [];
    
    /**
     * @var bool هل السجل جديد؟
     */
    protected $exists = false;
    
    /**
     * @var array استعلامات النطاق
     */
    protected $scopes = [];
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ
     * @param App_DB $db
     * @param string|null $table
     */
    public function __construct(App_DB $db, ?string $table = null) {
        $this->db = $db;
        
        if ($table) {
            $this->setTable($table);
        }
        
        $this->initialize();
    }
    
    /**
     * تهيئة الكلاس
     */
    protected function initialize(): void {
        $this->registerDefaultEvents();
        $this->detectPrimaryKey();
        $this->loadTableInfo();
    }
    
    /**
     * تعيين اسم الجدول
     * @param string $table
     * @return self
     */
    public function setTable(string $table): self {
        $this->table = $table;
        return $this;
    }
    
    /**
     * اكتشاف المفتاح الأساسي
     */
    protected function detectPrimaryKey(): void {
        if (!$this->table) {
            return;
        }
        
        $pk = $this->db->getPrimaryKey($this->table);
        
        if ($pk) {
            $this->primary_key = $pk;
        }
    }
    
    /**
     * تحميل معلومات الجدول
     */
    protected function loadTableInfo(): void {
        if (!$this->table) {
            return;
        }
        
        // يمكن تحميل معلومات إضافية عن الجدول
        // مثل أنواع الحقول، العلاقات، إلخ
    }
    
    /**
     * تسجيل الأحداث الافتراضية
     */
    protected function registerDefaultEvents(): void {
        $this->events = [
            'creating' => [],
            'created' => [],
            'updating' => [],
            'updated' => [],
            'deleting' => [],
            'deleted' => [],
            'saving' => [],
            'saved' => [],
            'retrieved' => []
        ];
    }
    
    // ==========================================
    // عمليات الإنشاء (Create)
    // ==========================================
    
    /**
     * إنشاء سجل جديد
     * @param array $data
     * @return int|false
     */
    public function create(array $data) {
        // تنظيف البيانات
        $data = $this->fillableFromArray($data);
        
        // التحقق من البيانات
        $validation = $this->validate($data, 'create');
        
        if (!$validation->passes()) {
            throw new Exception('Validation failed: ' . implode(', ', $validation->errors()));
        }
        
        // تشغيل حدث creating
        $this->fireEvent('creating', ['data' => &$data]);
        
        // إضافة الطوابع الزمنية
        if ($this->hasTimestamps()) {
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        // تنفيذ الإدراج
        $id = $this->db->insert($this->table, $data);
        
        if ($id) {
            // تشغيل حدث created
            $this->fireEvent('created', ['id' => $id, 'data' => $data]);
        }
        
        return $id;
    }
    
    /**
     * إنشاء سجلات متعددة
     * @param array $data
     * @return array
     */
    public function createMany(array $data): array {
        $ids = [];
        
        foreach ($data as $row) {
            $ids[] = $this->create($row);
        }
        
        return $ids;
    }
    
    /**
     * إنشاء أو تحديث
     * @param array $attributes
     * @param array $values
     * @return int
     */
    public function updateOrCreate(array $attributes, array $values = []): int {
        $existing = $this->findWhere($attributes);
        
        if ($existing) {
            $this->setAttributes($existing);
            $this->update($values);
            return $this->getKey();
        }
        
        return $this->create(array_merge($attributes, $values));
    }
    
    // ==========================================
    // عمليات القراءة (Read)
    // ==========================================
    
    /**
     * البحث عن سجل بالمعرف
     * @param mixed $id
     * @return array|null
     */
    public function find($id): ?array {
        $result = $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->primary_key} = ?",
            [$id]
        );
        
        if ($result) {
            $this->setAttributes($result);
            $result = $this->castAttributes($result);
            $result = $this->hideAttributes($result);
            
            // تشغيل حدث retrieved
            $this->fireEvent('retrieved', ['data' => $result]);
        }
        
        return $result;
    }
    
    /**
     * البحث عن سجل بشرط
     * @param array $conditions
     * @return array|null
     */
    public function findWhere(array $conditions): ?array {
        $builder = $this->newQuery();
        
        foreach ($conditions as $field => $value) {
            $builder->where($field, '=', $value);
        }
        
        return $builder->first();
    }
    
    /**
     * البحث عن سجلات بشرط
     * @param array $conditions
     * @return array
     */
    public function findAllWhere(array $conditions): array {
        $builder = $this->newQuery();
        
        foreach ($conditions as $field => $value) {
            $builder->where($field, '=', $value);
        }
        
        return $builder->get();
    }
    
    /**
     * الحصول على جميع السجلات
     * @param string|null $orderBy
     * @param string $direction
     * @return array
     */
    public function all(?string $orderBy = null, string $direction = 'ASC'): array {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }
        
        $results = $this->db->fetchAll($sql);
        
        foreach ($results as &$row) {
            $row = $this->castAttributes($row);
            $row = $this->hideAttributes($row);
        }
        
        return $results;
    }
    
    /**
     * الحصول على نتائج مقسمة
     * @param int $perPage
     * @param int $page
     * @return PaginationResult
     */
    public function paginate(int $perPage = 15, int $page = 1): PaginationResult {
        $total = $this->count();
        $offset = ($page - 1) * $perPage;
        
        $items = $this->db->fetchAll(
            "SELECT * FROM {$this->table} ORDER BY {$this->primary_key} DESC LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
        
        foreach ($items as &$row) {
            $row = $this->castAttributes($row);
            $row = $this->hideAttributes($row);
        }
        
        return new PaginationResult($items, $total, $perPage, $page);
    }
    
    /**
     * الحصول على أول سجل
     * @return array|null
     */
    public function first(): ?array {
        $result = $this->db->fetchOne(
            "SELECT * FROM {$this->table} ORDER BY {$this->primary_key} ASC LIMIT 1"
        );
        
        if ($result) {
            $result = $this->castAttributes($result);
            $result = $this->hideAttributes($result);
        }
        
        return $result;
    }
    
    /**
     * الحصول على آخر سجل
     * @return array|null
     */
    public function last(): ?array {
        $result = $this->db->fetchOne(
            "SELECT * FROM {$this->table} ORDER BY {$this->primary_key} DESC LIMIT 1"
        );
        
        if ($result) {
            $result = $this->castAttributes($result);
            $result = $this->hideAttributes($result);
        }
        
        return $result;
    }
    
    /**
     * الحصول على قيم حقل معين
     * @param string $field
     * @return array
     */
    public function pluck(string $field): array {
        return $this->db->fetchColumn(
            "SELECT {$field} FROM {$this->table} ORDER BY {$this->primary_key}"
        );
    }
    
    /**
     * الحصول على قيم حقلين كمصفوفة ترابطية
     * @param string $keyField
     * @param string $valueField
     * @return array
     */
    public function lists(string $keyField, string $valueField): array {
        return $this->db->fetchPairs(
            "SELECT {$keyField}, {$valueField} FROM {$this->table} ORDER BY {$valueField}",
            [],
            $keyField,
            $valueField
        );
    }
    
    // ==========================================
    // عمليات التحديث (Update)
    // ==========================================
    
    /**
     * تحديث سجل
     * @param mixed $id
     * @param array $data
     * @return bool
     */
    public function update($id, array $data): bool {
        // الحصول على السجل القديم
        $old = $this->find($id);
        
        if (!$old) {
            return false;
        }
        
        // تنظيف البيانات
        $data = $this->fillableFromArray($data);
        
        // التحقق من البيانات
        $validation = $this->validate($data, 'update', $id);
        
        if (!$validation->passes()) {
            throw new Exception('Validation failed: ' . implode(', ', $validation->errors()));
        }
        
        // تشغيل حدث updating
        $this->fireEvent('updating', ['id' => $id, 'old' => $old, 'new' => &$data]);
        
        // إضافة الطابع الزمني
        if ($this->hasTimestamps()) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        // تنفيذ التحديث
        $result = $this->db->update($this->table, $data, [$this->primary_key => $id]);
        
        if ($result) {
            // تشغيل حدث updated
            $this->fireEvent('updated', ['id' => $id, 'old' => $old, 'new' => $data]);
        }
        
        return (bool)$result;
    }
    
    /**
     * تحديث سجلات بشرط
     * @param array $conditions
     * @param array $data
     * @return int
     */
    public function updateWhere(array $conditions, array $data): int {
        // تنظيف البيانات
        $data = $this->fillableFromArray($data);
        
        // إضافة الطابع الزمني
        if ($this->hasTimestamps()) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        // بناء شرط WHERE
        $whereParts = [];
        $params = [];
        
        foreach ($conditions as $field => $value) {
            $whereParts[] = "{$field} = ?";
            $params[] = $value;
        }
        
        $whereClause = implode(' AND ', $whereParts);
        
        // إضافة بيانات التحديث للمعلمات
        foreach ($data as $value) {
            $params[] = $value;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(' = ?, ', array_keys($data)) . " = ? WHERE {$whereClause}";
        
        $statement = $this->db->query($sql, $params);
        return $statement->rowCount();
    }
    
    /**
     * تحديث أو إدراج
     * @param array $data
     * @return int
     */
    public function updateOrInsert(array $data): int {
        if (isset($data[$this->primary_key])) {
            $existing = $this->find($data[$this->primary_key]);
            
            if ($existing) {
                $this->update($data[$this->primary_key], $data);
                return $data[$this->primary_key];
            }
        }
        
        return $this->create($data);
    }
    
    // ==========================================
    // عمليات الحذف (Delete)
    // ==========================================
    
    /**
     * حذف سجل
     * @param mixed $id
     * @return bool
     */
    public function delete($id): bool {
        // الحصول على السجل
        $old = $this->find($id);
        
        if (!$old) {
            return false;
        }
        
        // تشغيل حدث deleting
        $this->fireEvent('deleting', ['id' => $id, 'data' => $old]);
        
        // تنفيذ الحذف
        $result = $this->db->delete($this->table, [$this->primary_key => $id]);
        
        if ($result) {
            // تشغيل حدث deleted
            $this->fireEvent('deleted', ['id' => $id, 'data' => $old]);
        }
        
        return (bool)$result;
    }
    
    /**
     * حذف سجلات بشرط
     * @param array $conditions
     * @return int
     */
    public function deleteWhere(array $conditions): int {
        // بناء شرط WHERE
        $whereParts = [];
        $params = [];
        
        foreach ($conditions as $field => $value) {
            $whereParts[] = "{$field} = ?";
            $params[] = $value;
        }
        
        $whereClause = implode(' AND ', $whereParts);
        
        $sql = "DELETE FROM {$this->table} WHERE {$whereClause}";
        
        $statement = $this->db->query($sql, $params);
        return $statement->rowCount();
    }
    
    /**
     * حذف جميع السجلات
     * @return int
     */
    public function deleteAll(): int {
        $sql = "DELETE FROM {$this->table}";
        $statement = $this->db->query($sql);
        return $statement->rowCount();
    }
    
    /**
     * تفريغ الجدول
     * @return bool
     */
    public function truncate(): bool {
        $sql = "TRUNCATE TABLE {$this->table}";
        $this->db->query($sql);
        return true;
    }
    
    // ==========================================
    // دوال إحصائية
    // ==========================================
    
    /**
     * عدد السجلات
     * @param array $conditions
     * @return int
     */
    public function count(array $conditions = []): int {
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $whereParts[] = "{$field} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        return (int)$this->db->fetchColumn($sql, $params);
    }
    
    /**
     * القيمة القصوى
     * @param string $field
     * @param array $conditions
     * @return mixed
     */
    public function max(string $field, array $conditions = []) {
        $sql = "SELECT MAX({$field}) FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $whereParts[] = "{$field} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        return $this->db->fetchColumn($sql, $params);
    }
    
    /**
     * القيمة الدنيا
     * @param string $field
     * @param array $conditions
     * @return mixed
     */
    public function min(string $field, array $conditions = []) {
        $sql = "SELECT MIN({$field}) FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $whereParts[] = "{$field} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        return $this->db->fetchColumn($sql, $params);
    }
    
    /**
     * متوسط القيم
     * @param string $field
     * @param array $conditions
     * @return float
     */
    public function avg(string $field, array $conditions = []): float {
        $sql = "SELECT AVG({$field}) FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $whereParts[] = "{$field} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        return (float)$this->db->fetchColumn($sql, $params);
    }
    
    /**
     * مجموع القيم
     * @param string $field
     * @param array $conditions
     * @return float
     */
    public function sum(string $field, array $conditions = []): float {
        $sql = "SELECT SUM({$field}) FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $whereParts[] = "{$field} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        return (float)$this->db->fetchColumn($sql, $params);
    }
    
    /**
     * التحقق من وجود سجلات
     * @param array $conditions
     * @return bool
     */
    public function exists(array $conditions = []): bool {
        return $this->count($conditions) > 0;
    }
    
    /**
     * التحقق من عدم وجود سجلات
     * @param array $conditions
     * @return bool
     */
    public function doesntExist(array $conditions = []): bool {
        return !$this->exists($conditions);
    }
    
    // ==========================================
    // معالجة البيانات
    // ==========================================
    
    /**
     * تعيين الخصائص
     * @param array $attributes
     * @return self
     */
    public function setAttributes(array $attributes): self {
        $this->attributes = $attributes;
        $this->exists = true;
        return $this;
    }
    
    /**
     * الحصول على الخصائص
     * @return array
     */
    public function getAttributes(): array {
        return $this->attributes;
    }
    
    /**
     * الحصول على قيمة خاصية
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getAttribute(string $key, $default = null) {
        return $this->attributes[$key] ?? $default;
    }
    
    /**
     * تعيين قيمة خاصية
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setAttribute(string $key, $value): self {
        $old = $this->attributes[$key] ?? null;
        
        if ($old != $value) {
            $this->changes[$key] = $value;
        }
        
        $this->attributes[$key] = $value;
        
        return $this;
    }
    
    /**
     * الحصول على المفتاح الأساسي
     * @return mixed
     */
    public function getKey() {
        return $this->attributes[$this->primary_key] ?? null;
    }
    
    /**
     * حفظ التغييرات
     * @return bool
     */
    public function save(): bool {
        if (empty($this->changes)) {
            return true;
        }
        
        $key = $this->getKey();
        
        if ($key && $this->exists) {
            return $this->update($key, $this->changes);
        } else {
            $newKey = $this->create($this->attributes);
            
            if ($newKey) {
                $this->attributes[$this->primary_key] = $newKey;
                $this->exists = true;
                $this->changes = [];
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * تحديث السجل بالبيانات الحالية
     * @return bool
     */
    public function refresh(): bool {
        $key = $this->getKey();
        
        if (!$key) {
            return false;
        }
        
        $fresh = $this->find($key);
        
        if ($fresh) {
            $this->attributes = $fresh;
            return true;
        }
        
        return false;
    }
    
    /**
     * إنشاء نسخة جديدة
     * @return self
     */
    public function replicate(): self {
        $new = clone $this;
        $new->attributes = $this->attributes;
        $new->exists = false;
        $new->changes = [];
        
        unset($new->attributes[$this->primary_key]);
        
        return $new;
    }
    
    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array {
        return $this->hideAttributes($this->castAttributes($this->attributes));
    }
    
    /**
     * تحويل إلى JSON
     * @return string
     */
    public function toJson(): string {
        return json_encode($this->toArray());
    }
    
    /**
     * تنظيف البيانات حسب الحقول المسموحة
     * @param array $data
     * @return array
     */
    protected function fillableFromArray(array $data): array {
        if (!empty($this->fillable)) {
            return array_intersect_key($data, array_flip($this->fillable));
        }
        
        if (!empty($this->guarded)) {
            return array_diff_key($data, array_flip($this->guarded));
        }
        
        return $data;
    }
    
    /**
     * إخفاء الحقول
     * @param array $data
     * @return array
     */
    protected function hideAttributes(array $data): array {
        foreach ($this->hidden as $field) {
            unset($data[$field]);
        }
        
        return $data;
    }
    
    /**
     * تحويل أنواع الحقول
     * @param array $data
     * @return array
     */
    protected function castAttributes(array $data): array {
        foreach ($this->casts as $field => $type) {
            if (!isset($data[$field])) {
                continue;
            }
            
            switch ($type) {
                case 'int':
                case 'integer':
                    $data[$field] = (int)$data[$field];
                    break;
                    
                case 'float':
                case 'double':
                case 'real':
                    $data[$field] = (float)$data[$field];
                    break;
                    
                case 'bool':
                case 'boolean':
                    $data[$field] = (bool)$data[$field];
                    break;
                    
                case 'string':
                    $data[$field] = (string)$data[$field];
                    break;
                    
                case 'array':
                case 'json':
                    $data[$field] = json_decode($data[$field], true) ?: [];
                    break;
                    
                case 'object':
                    $data[$field] = json_decode($data[$field]);
                    break;
                    
                case 'date':
                    $data[$field] = $data[$field] ? date('Y-m-d', strtotime($data[$field])) : null;
                    break;
                    
                case 'datetime':
                    $data[$field] = $data[$field] ? date('Y-m-d H:i:s', strtotime($data[$field])) : null;
                    break;
                    
                case 'timestamp':
                    $data[$field] = $data[$field] ? strtotime($data[$field]) : null;
                    break;
            }
        }
        
        return $data;
    }
    
    // ==========================================
    // التحقق من البيانات
    // ==========================================
    
    /**
     * التحقق من صحة البيانات
     * @param array $data
     * @param string $scenario
     * @param mixed $id
     * @return ValidationResult
     */
    public function validate(array $data, string $scenario = 'create', $id = null): ValidationResult {
        $errors = [];
        $rules = $this->getRules($scenario);
        
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $fieldRules = explode('|', $fieldRules);
            
            foreach ($fieldRules as $rule) {
                $result = $this->validateField($field, $value, $rule, $data, $id);
                
                if ($result !== true) {
                    $errors[$field][] = $result;
                }
            }
        }
        
        return new ValidationResult(empty($errors), $errors, $data);
    }
    
    /**
     * الحصول على قواعد التحقق
     * @param string $scenario
     * @return array
     */
    protected function getRules(string $scenario = 'create'): array {
        $rules = $this->rules;
        
        if ($scenario === 'update' && isset($rules['update'])) {
            return $rules['update'];
        }
        
        if ($scenario === 'create' && isset($rules['create'])) {
            return $rules['create'];
        }
        
        return $rules;
    }
    
    /**
     * التحقق من حقل
     * @param string $field
     * @param mixed $value
     * @param string $rule
     * @param array $data
     * @param mixed $id
     * @return bool|string
     */
    protected function validateField(string $field, $value, string $rule, array $data = [], $id = null) {
        $params = [];
        
        if (strpos($rule, ':') !== false) {
            list($rule, $paramStr) = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        }
        
        switch ($rule) {
            case 'required':
                if (empty($value) && $value !== '0') {
                    return $this->getMessage('required', $field);
                }
                break;
                
            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $this->getMessage('email', $field);
                }
                break;
                
            case 'min':
                if (strlen($value) < (int)$params[0]) {
                    return str_replace(':min', $params[0], $this->getMessage('min', $field));
                }
                break;
                
            case 'max':
                if (strlen($value) > (int)$params[0]) {
                    return str_replace(':max', $params[0], $this->getMessage('max', $field));
                }
                break;
                
            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->getMessage('numeric', $field);
                }
                break;
                
            case 'integer':
                if (!filter_var($value, FILTER_VALIDATE_INT)) {
                    return $this->getMessage('integer', $field);
                }
                break;
                
            case 'unique':
                $table = $params[0] ?? $this->table;
                $ignoreId = $params[1] ?? null;
                
                $sql = "SELECT COUNT(*) FROM {$table} WHERE {$field} = ?";
                $queryParams = [$value];
                
                if ($ignoreId && $ignoreId == $id) {
                    $sql .= " AND {$this->primary_key} != ?";
                    $queryParams[] = $id;
                }
                
                $count = $this->db->fetchColumn($sql, $queryParams);
                
                if ($count > 0) {
                    return $this->getMessage('unique', $field);
                }
                break;
                
            case 'exists':
                $table = $params[0] ?? $this->table;
                $column = $params[1] ?? $field;
                
                $count = $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?",
                    [$value]
                );
                
                if ($count == 0) {
                    return $this->getMessage('exists', $field);
                }
                break;
                
            case 'in':
                if (!in_array($value, $params)) {
                    return $this->getMessage('in', $field);
                }
                break;
                
            case 'confirmed':
                if ($value != ($data[$field . '_confirmation'] ?? null)) {
                    return $this->getMessage('confirmed', $field);
                }
                break;
        }
        
        return true;
    }
    
    /**
     * الحصول على رسالة خطأ
     * @param string $rule
     * @param string $field
     * @return string
     */
    protected function getMessage(string $rule, string $field): string {
        $messages = [
            'required' => 'حقل :field مطلوب',
            'email' => 'حقل :field يجب أن يكون بريداً إلكترونياً صحيحاً',
            'min' => 'حقل :field يجب أن يكون على الأقل :min أحرف',
            'max' => 'حقل :field يجب أن يكون على الأكثر :max أحرف',
            'numeric' => 'حقل :field يجب أن يكون رقماً',
            'integer' => 'حقل :field يجب أن يكون عدداً صحيحاً',
            'unique' => 'قيمة حقل :field مستخدمة بالفعل',
            'exists' => 'قيمة حقل :field غير موجودة',
            'in' => 'قيمة حقل :field غير مسموحة',
            'confirmed' => 'تأكيد حقل :field غير متطابق'
        ];
        
        $message = $this->messages[$rule] ?? $messages[$rule] ?? 'خطأ في التحقق';
        
        return str_replace(':field', $field, $message);
    }
    
    // ==========================================
    // Query Builder
    // ==========================================
    
    /**
     * إنشاء Query Builder جديد
     * @return Query_Builder
     */
    public function newQuery(): Query_Builder {
        return new Query_Builder($this->db, $this->table);
    }
    
    /**
     * معالجة الدفعات
     * @param int $size
     * @param callable $callback
     * @return bool
     */
    public function chunk(int $size, callable $callback): bool {
        $page = 1;
        
        do {
            $results = $this->paginate($size, $page);
            
            if (count($results->items()) === 0) {
                break;
            }
            
            if ($callback($results->items(), $page) === false) {
                return false;
            }
            
            $page++;
            
        } while ($results->hasMorePages());
        
        return true;
    }
    
    /**
     * تطبيق النطاقات
     * @param string $name
     * @param array $arguments
     * @return self
     */
    public function scope(string $name, ...$arguments): self {
        if (isset($this->scopes[$name])) {
            $callback = $this->scopes[$name];
            $callback($this, ...$arguments);
        }
        
        return $this;
    }
    
    /**
     * إضافة نطاق
     * @param string $name
     * @param callable $callback
     */
    public function addScope(string $name, callable $callback): void {
        $this->scopes[$name] = $callback;
    }
    
    // ==========================================
    // العلاقات
    // ==========================================
    
    /**
     * علاقة belongsTo
     * @param string $related
     * @param string $foreignKey
     * @param string $ownerKey
     * @return array|null
     */
    public function belongsTo(string $related, string $foreignKey = null, string $ownerKey = 'id'): ?array {
        $foreignKey = $foreignKey ?: strtolower($related) . '_id';
        $relatedId = $this->getAttribute($foreignKey);
        
        if (!$relatedId) {
            return null;
        }
        
        $relatedModel = new CRUD_App($this->db, $related);
        return $relatedModel->find($relatedId);
    }
    
    /**
     * علاقة hasMany
     * @param string $related
     * @param string $foreignKey
     * @param string $localKey
     * @return array
     */
    public function hasMany(string $related, string $foreignKey = null, string $localKey = null): array {
        $localKey = $localKey ?: $this->primary_key;
        $foreignKey = $foreignKey ?: $this->table . '_' . $localKey;
        
        $localValue = $this->getAttribute($localKey);
        
        $relatedModel = new CRUD_App($this->db, $related);
        return $relatedModel->findAllWhere([$foreignKey => $localValue]);
    }
    
    /**
     * علاقة hasOne
     * @param string $related
     * @param string $foreignKey
     * @param string $localKey
     * @return array|null
     */
    public function hasOne(string $related, string $foreignKey = null, string $localKey = null): ?array {
        $localKey = $localKey ?: $this->primary_key;
        $foreignKey = $foreignKey ?: $this->table . '_' . $localKey;
        
        $localValue = $this->getAttribute($localKey);
        
        $relatedModel = new CRUD_App($this->db, $related);
        return $relatedModel->findWhere([$foreignKey => $localValue]);
    }
    
    /**
     * علاقة belongsToMany
     * @param string $related
     * @param string $table
     * @param string $foreignPivotKey
     * @param string $relatedPivotKey
     * @return array
     */
    public function belongsToMany(string $related, string $table = null, string $foreignPivotKey = null, string $relatedPivotKey = null): array {
        $foreignPivotKey = $foreignPivotKey ?: $this->table . '_' . $this->primary_key;
        $relatedPivotKey = $relatedPivotKey ?: $related . '_id';
        $table = $table ?: $this->table . '_' . $related;
        
        $localValue = $this->getAttribute($this->primary_key);
        
        $sql = "SELECT r.* FROM {$related} r
                JOIN {$table} p ON r.id = p.{$relatedPivotKey}
                WHERE p.{$foreignPivotKey} = ?";
        
        $results = $this->db->fetchAll($sql, [$localValue]);
        
        foreach ($results as &$row) {
            $row = (new CRUD_App($this->db, $related))->castAttributes($row);
        }
        
        return $results;
    }
    
    // ==========================================
    // الأحداث
    // ==========================================
    
    /**
     * تسجيل حدث
     * @param string $event
     * @param callable $callback
     */
    public function on(string $event, callable $callback): void {
        if (!isset($this->events[$event])) {
            $this->events[$event] = [];
        }
        
        $this->events[$event][] = $callback;
    }
    
    /**
     * إطلاق حدث
     * @param string $event
     * @param array $payload
     */
    protected function fireEvent(string $event, array $payload = []): void {
        if (!isset($this->events[$event])) {
            return;
        }
        
        foreach ($this->events[$event] as $callback) {
            call_user_func($callback, $payload);
        }
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * التحقق من وجود طوابع زمنية
     * @return bool
     */
    protected function hasTimestamps(): bool {
        return !in_array('created_at', $this->guarded) || !in_array('updated_at', $this->guarded);
    }
    
    /**
     * الحصول على نسخة جديدة
     * @param array $attributes
     * @return self
     */
    public function newInstance(array $attributes = []): self {
        $instance = new static($this->db, $this->table);
        
        if (!empty($attributes)) {
            $instance->setAttributes($attributes);
        }
        
        return $instance;
    }
    
    /**
     * بدء معاملة
     */
    public function beginTransaction(): void {
        $this->db->beginTransaction();
    }
    
    /**
     * تأكيد المعاملة
     */
    public function commit(): void {
        $this->db->commit();
    }
    
    /**
     * تراجع عن المعاملة
     */
    public function rollback(): void {
        $this->db->rollback();
    }
    
    /**
     * تنفيذ داخل معاملة
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback) {
        return $this->db->transaction($callback);
    }
    
    // ==========================================
    // Magic Methods
    // ==========================================
    
    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        // التحقق من النطاقات
        if (strpos($name, 'scope') === 0) {
            $scope = lcfirst(substr($name, 5));
            return $this->scope($scope, ...$arguments);
        }
        
        // التحقق من العلاقات
        if (in_array($name, ['belongsTo', 'hasMany', 'hasOne', 'belongsToMany'])) {
            return $this->$name(...$arguments);
        }
        
        throw new Exception("Method {$name} not found");
    }
    
    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name) {
        // التحقق من العلاقة
        if (method_exists($this, $name)) {
            return $this->$name();
        }
        
        return $this->getAttribute($name);
    }
    
    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set(string $name, $value) {
        $this->setAttribute($name, $value);
    }
    
    /**
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool {
        return isset($this->attributes[$name]);
    }
    
    /**
     * @return string
     */
    public function __toString(): string {
        return $this->toJson();
    }
}

/**
 * PaginationResult
 * @package CRUD
 * 
 * نتائج التقسيم
 */
class PaginationResult {
    
    /**
     * @var array
     */
    private $items;
    
    /**
     * @var int
     */
    private $total;
    
    /**
     * @var int
     */
    private $perPage;
    
    /**
     * @var int
     */
    private $currentPage;
    
    /**
     * @var int
     */
    private $lastPage;
    
    /**
     * المُنشئ
     * @param array $items
     * @param int $total
     * @param int $perPage
     * @param int $currentPage
     */
    public function __construct(array $items, int $total, int $perPage, int $currentPage) {
        $this->items = $items;
        $this->total = $total;
        $this->perPage = $perPage;
        $this->currentPage = $currentPage;
        $this->lastPage = (int)ceil($total / $perPage);
    }
    
    /**
     * الحصول على العناصر
     * @return array
     */
    public function items(): array {
        return $this->items;
    }
    
    /**
     * الحصول على العدد الإجمالي
     * @return int
     */
    public function total(): int {
        return $this->total;
    }
    
    /**
     * الحصول على عدد العناصر في الصفحة
     * @return int
     */
    public function perPage(): int {
        return $this->perPage;
    }
    
    /**
     * الحصول على الصفحة الحالية
     * @return int
     */
    public function currentPage(): int {
        return $this->currentPage;
    }
    
    /**
     * الحصول على آخر صفحة
     * @return int
     */
    public function lastPage(): int {
        return $this->lastPage;
    }
    
    /**
     * التحقق من وجود صفحات أكثر
     * @return bool
     */
    public function hasMorePages(): bool {
        return $this->currentPage < $this->lastPage;
    }
    
    /**
     * الحصول على رابط الصفحة التالية
     * @return string|null
     */
    public function nextPageUrl(): ?string {
        if (!$this->hasMorePages()) {
            return null;
        }
        
        return "?page=" . ($this->currentPage + 1);
    }
    
    /**
     * الحصول على رابط الصفحة السابقة
     * @return string|null
     */
    public function previousPageUrl(): ?string {
        if ($this->currentPage <= 1) {
            return null;
        }
        
        return "?page=" . ($this->currentPage - 1);
    }
    
    /**
     * الحصول على أرقام الصفحات
     * @param int $count
     * @return array
     */
    public function getPageNumbers(int $count = 5): array {
        $start = max(1, $this->currentPage - floor($count / 2));
        $end = min($this->lastPage, $start + $count - 1);
        $start = max(1, $end - $count + 1);
        
        return range($start, $end);
    }
    
    /**
     * الحصول على الروابط
     * @return array
     */
    public function links(): array {
        $links = [];
        
        for ($i = 1; $i <= $this->lastPage; $i++) {
            $links[] = [
                'page' => $i,
                'url' => "?page={$i}",
                'active' => $i == $this->currentPage
            ];
        }
        
        return $links;
    }
    
    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array {
        return [
            'items' => $this->items,
            'pagination' => [
                'total' => $this->total,
                'per_page' => $this->perPage,
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage,
                'has_more' => $this->hasMorePages()
            ]
        ];
    }
}

/**
 * ValidationResult
 * @package CRUD
 * 
 * نتائج التحقق
 */
class ValidationResult {
    
    /**
     * @var bool
     */
    private $valid;
    
    /**
     * @var array
     */
    private $errors;
    
    /**
     * @var array
     */
    private $validatedData;
    
    /**
     * المُنشئ
     * @param bool $valid
     * @param array $errors
     * @param array $validatedData
     */
    public function __construct(bool $valid, array $errors = [], array $validatedData = []) {
        $this->valid = $valid;
        $this->errors = $errors;
        $this->validatedData = $validatedData;
    }
    
    /**
     * التحقق من الفشل
     * @return bool
     */
    public function fails(): bool {
        return !$this->valid;
    }
    
    /**
     * التحقق من النجاح
     * @return bool
     */
    public function passes(): bool {
        return $this->valid;
    }
    
    /**
     * الحصول على الأخطاء
     * @return array
     */
    public function errors(): array {
        return $this->errors;
    }
    
    /**
     * الحصول على خطأ حقل معين
     * @param string $field
     * @return string|null
     */
    public function getError(string $field): ?string {
        return $this->errors[$field][0] ?? null;
    }
    
    /**
     * الحصول على البيانات المحققة
     * @return array
     */
    public function validatedData(): array {
        return $this->validatedData;
    }
}

/**
 * Model_Events
 * @package CRUD
 * 
 * إدارة أحداث النموذج
 */
class Model_Events {
    
    /**
     * @var array
     */
    private $listeners = [];
    
    /**
     * تسجيل مستمع
     * @param string $event
     * @param callable $callback
     */
    public function listen(string $event, callable $callback): void {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        
        $this->listeners[$event][] = $callback;
    }
    
    /**
     * إطلاق حدث
     * @param string $event
     * @param mixed $data
     * @return mixed
     */
    public function fire(string $event, $data = null) {
        if (!isset($this->listeners[$event])) {
            return null;
        }
        
        $results = [];
        
        foreach ($this->listeners[$event] as $callback) {
            $results[] = $callback($data);
        }
        
        return $results;
    }
    
    /**
     * مسح جميع المستمعين
     */
    public function flush(): void {
        $this->listeners = [];
    }
}

/**
 * Query_Scope
 * @package CRUD
 * 
 * نطاق استعلام
 */
class Query_Scope {
    
    /**
     * @var string
     */
    private $name;
    
    /**
     * @var callable
     */
    private $callback;
    
    /**
     * المُنشئ
     * @param string $name
     * @param callable $callback
     */
    public function __construct(string $name, callable $callback) {
        $this->name = $name;
        $this->callback = $callback;
    }
    
    /**
     * الحصول على الاسم
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * تطبيق النطاق
     * @param Query_Builder $query
     * @param array $arguments
     */
    public function apply(Query_Builder $query, array $arguments = []): void {
        call_user_func($this->callback, $query, ...$arguments);
    }
}

// ==========================================
// ثوابت
// ==========================================

if (!defined('CRUD_SORT_ASC')) {
    define('CRUD_SORT_ASC', 'ASC');
    define('CRUD_SORT_DESC', 'DESC');
}

?>