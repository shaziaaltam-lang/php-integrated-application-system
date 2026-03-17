<?php
/**
 * صلاحيات_App.php
 * @version 1.0.0
 * @package Permissions
 * 
 * نظام الصلاحيات والأدوار المتكامل
 * يدخل إدارة الأدوار، الصلاحيات، والتحكم في الوصول
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * صلاحيات_App
 * @package Permissions
 * 
 * الكلاس الرئيسي لنظام الصلاحيات
 */
class Permissions_app {
    
    // ==========================================
    // الخصائص
    // ==========================================
    
    /**
     * @var App_DB|null قاعدة البيانات
     */
    private $db = null;
    
    /**
     * @var App_Session|null الجلسة
     */
    private $session = null;
    
    /**
     * @var array ذاكرة التخزين المؤقت للصلاحيات
     */
    private $cache = [];
    
    /**
     * @var array شجرة الصلاحيات
     */
    private $permissions_tree = [];
    
    /**
     * @var array الأدوار الافتراضية
     */
    private $default_roles = [
        'admin' => 'مدير النظام - لديه جميع الصلاحيات',
        'manager' => 'مدير - صلاحيات متقدمة',
        'user' => 'مستخدم عادي - صلاحيات أساسية',
        'guest' => 'زائر - صلاحيات محدودة'
    ];
    
    /**
     * @var bool حالة التخزين المؤقت
     */
    private $cache_enabled = true;
    
    /**
     * @var int وقت انتهاء التخزين المؤقت (ثواني)
     */
    private $cache_ttl = 3600;
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ
     * @param App_DB $db
     * @param App_Session|null $session
     */
    public function __construct(App_DB $db, ?App_Session $session = null) {
        $this->db = $db;
        $this->session = $session;
        
        $this->initializeTables();
        $this->loadPermissionsTree();
        $this->ensureDefaultRoles();
    }
    
    /**
     * التأكد من وجود جداول الصلاحيات
     */
    private function initializeTables(): void {
        // جدول الأدوار
        if (!$this->db->tableExists('roles')) {
            $this->db->query("
                CREATE TABLE roles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(50) UNIQUE NOT NULL,
                    display_name VARCHAR(100),
                    description TEXT,
                    parent_id INT NULL,
                    level INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (parent_id) REFERENCES roles(id) ON DELETE SET NULL,
                    INDEX idx_parent (parent_id),
                    INDEX idx_level (level)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول الصلاحيات
        if (!$this->db->tableExists('permissions')) {
            $this->db->query("
                CREATE TABLE permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) UNIQUE NOT NULL,
                    display_name VARCHAR(100),
                    description TEXT,
                    resource VARCHAR(50),
                    action VARCHAR(50),
                    conditions JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_resource (resource, action)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول صلاحيات الأدوار
        if (!$this->db->tableExists('role_permissions')) {
            $this->db->query("
                CREATE TABLE role_permissions (
                    role_id INT,
                    permission_id INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (role_id, permission_id),
                    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول أدوار المستخدمين
        if (!$this->db->tableExists('user_roles')) {
            $this->db->query("
                CREATE TABLE user_roles (
                    user_id INT,
                    role_id INT,
                    assigned_by INT,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at TIMESTAMP NULL,
                    PRIMARY KEY (user_id, role_id),
                    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول صلاحيات المستخدمين المباشرة
        if (!$this->db->tableExists('user_permissions')) {
            $this->db->query("
                CREATE TABLE user_permissions (
                    user_id INT,
                    permission_id INT,
                    granted BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, permission_id),
                    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }
    
    /**
     * تحميل شجرة الصلاحيات
     */
    private function loadPermissionsTree(): void {
        $permissions = $this->db->fetchAll("SELECT * FROM permissions ORDER BY resource, action");
        
        foreach ($permissions as $perm) {
            $resource = $perm['resource'] ?? 'general';
            
            if (!isset($this->permissions_tree[$resource])) {
                $this->permissions_tree[$resource] = [];
            }
            
            $this->permissions_tree[$resource][$perm['action']] = $perm;
        }
    }
    
    /**
     * التأكد من وجود الأدوار الافتراضية
     */
    private function ensureDefaultRoles(): void {
        foreach ($this->default_roles as $role_name => $description) {
            if (!$this->getRoleByName($role_name)) {
                $this->createRole($role_name, $description);
            }
        }
    }
    
    // ==========================================
    // دوال التحقق من الصلاحيات
    // ==========================================
    
    /**
     * التحقق من صلاحية لمستخدم
     * @param int $user_id
     * @param string $permission
     * @param mixed $resource_id
     * @return bool
     */
    public function checkPermission(int $user_id, string $permission, $resource_id = null): bool {
        // التحقق من الذاكرة المؤقتة
        $cache_key = "perm_{$user_id}_{$permission}_" . ($resource_id ?? 'null');
        
        if ($this->cache_enabled && isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        // الحصول على صلاحيات المستخدم
        $permissions = $this->getUserPermissions($user_id);
        
        // التحقق من الصلاحية
        $has_permission = in_array($permission, $permissions) || 
                          $this->checkWildcardPermission($permission, $permissions);
        
        // إذا كان هناك مورد محدد، تحقق من شروط إضافية
        if ($has_permission && $resource_id !== null) {
            $has_permission = $this->checkResourcePermission($user_id, $permission, $resource_id);
        }
        
        // تخزين في الذاكرة المؤقتة
        if ($this->cache_enabled) {
            $this->cache[$cache_key] = $has_permission;
        }
        
        return $has_permission;
    }
    
    /**
     * التحقق من أي صلاحية من قائمة
     * @param int $user_id
     * @param array $permissions
     * @return bool
     */
    public function checkAnyPermission(int $user_id, array $permissions): bool {
        foreach ($permissions as $permission) {
            if ($this->checkPermission($user_id, $permission)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * التحقق من جميع الصلاحيات
     * @param int $user_id
     * @param array $permissions
     * @return bool
     */
    public function checkAllPermissions(int $user_id, array $permissions): bool {
        foreach ($permissions as $permission) {
            if (!$this->checkPermission($user_id, $permission)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * التحقق من صلاحية wildcard (مثل users.*)
     * @param string $permission
     * @param array $user_permissions
     * @return bool
     */
    private function checkWildcardPermission(string $permission, array $user_permissions): bool {
        $parts = explode('.', $permission);
        
        // بناء أنماط wildcard
        $patterns = [];
        $pattern = '';
        
        foreach ($parts as $i => $part) {
            $pattern .= ($i > 0 ? '.' : '') . $part;
            $patterns[] = $pattern . '.*';
        }
        
        // التحقق من الأنماط
        foreach ($patterns as $pattern) {
            if (in_array($pattern, $user_permissions)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * التحقق من صلاحية على مورد محدد
     * @param int $user_id
     * @param string $permission
     * @param mixed $resource_id
     * @return bool
     */
    private function checkResourcePermission(int $user_id, string $permission, $resource_id): bool {
        // الحصول على معلومات الصلاحية
        $perm_data = $this->getPermissionData($permission);
        
        if (!$perm_data || empty($perm_data['conditions'])) {
            return true;
        }
        
        // تطبيق الشروط على المورد
        $conditions = json_decode($perm_data['conditions'], true);
        
        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($condition, $user_id, $resource_id)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * تقييم شرط
     * @param array $condition
     * @param int $user_id
     * @param mixed $resource_id
     * @return bool
     */
    private function evaluateCondition(array $condition, int $user_id, $resource_id): bool {
        switch ($condition['type'] ?? '') {
            case 'owner':
                // التحقق من أن المستخدم هو مالك المورد
                return $this->isResourceOwner($user_id, $resource_id, $condition['resource'] ?? null);
                
            case 'field':
                // التحقق من قيمة حقل معين
                return $this->checkResourceField($resource_id, $condition['field'], $condition['value']);
                
            case 'callback':
                // استخدام دالة callback مخصصة
                if (isset($condition['callback']) && is_callable($condition['callback'])) {
                    return $condition['callback']($user_id, $resource_id);
                }
                break;
        }
        
        return true;
    }
    
    /**
     * التحقق من ملكية المورد
     * @param int $user_id
     * @param mixed $resource_id
     * @param string|null $resource_type
     * @return bool
     */
    private function isResourceOwner(int $user_id, $resource_id, ?string $resource_type = null): bool {
        if (!$resource_type) {
            return false;
        }
        
        // التحقق من جدول المورد
        $table = $resource_type . 's'; // posts, comments, etc.
        $owner_field = $resource_type . '_owner_id';
        
        $owner_id = $this->db->fetchColumn(
            "SELECT {$owner_field} FROM {$table} WHERE id = ?",
            [$resource_id]
        );
        
        return $owner_id == $user_id;
    }
    
    /**
     * التحقق من حقل في المورد
     * @param mixed $resource_id
     * @param string $field
     * @param mixed $value
     * @return bool
     */
    private function checkResourceField($resource_id, string $field, $value): bool {
        // تنفيذ حسب نوع المورد
        return true; // تبسيطاً
    }
    
    // ==========================================
    // دوال إدارة الأدوار
    // ==========================================
    
    /**
     * تعيين دور لمستخدم
     * @param int $user_id
     * @param mixed $role
     * @param string|null $expires_at
     * @return bool
     */
    public function assignRole(int $user_id, $role, ?string $expires_at = null): bool {
        $role_id = is_numeric($role) ? $role : $this->getRoleIdByName($role);
        
        if (!$role_id) {
            return false;
        }
        
        // حذف الدور القديم إذا موجود
        $this->db->delete('user_roles', [
            'user_id' => $user_id,
            'role_id' => $role_id
        ]);
        
        // إضافة الدور الجديد
        $data = [
            'user_id' => $user_id,
            'role_id' => $role_id,
            'assigned_at' => date('Y-m-d H:i:s')
        ];
        
        if ($expires_at) {
            $data['expires_at'] = $expires_at;
        }
        
        // إضافة معرف المعين (من الجلسة)
        if ($this->session && $this->session->getUser()) {
            $data['assigned_by'] = $this->session->getUser()['id'] ?? null;
        }
        
        $result = $this->db->insert('user_roles', $data);
        
        // مسح الذاكرة المؤقتة
        $this->clearUserCache($user_id);
        
        return (bool)$result;
    }
    
    /**
     * إزالة دور من مستخدم
     * @param int $user_id
     * @param mixed $role
     * @return bool
     */
    public function removeRole(int $user_id, $role): bool {
        $role_id = is_numeric($role) ? $role : $this->getRoleIdByName($role);
        
        if (!$role_id) {
            return false;
        }
        
        $result = $this->db->delete('user_roles', [
            'user_id' => $user_id,
            'role_id' => $role_id
        ]);
        
        // مسح الذاكرة المؤقتة
        $this->clearUserCache($user_id);
        
        return (bool)$result;
    }
    
    /**
     * الحصول على أدوار المستخدم
     * @param int $user_id
     * @return array
     */
    public function getUserRoles(int $user_id): array {
        $cache_key = "user_roles_{$user_id}";
        
        if ($this->cache_enabled && isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        $roles = $this->db->fetchAll("
            SELECT r.* FROM roles r
            JOIN user_roles ur ON r.id = ur.role_id
            WHERE ur.user_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
        ", [$user_id]);
        
        if ($this->cache_enabled) {
            $this->cache[$cache_key] = $roles;
        }
        
        return $roles;
    }
    
    /**
     * التحقق من وجود دور لمستخدم
     * @param int $user_id
     * @param mixed $role
     * @return bool
     */
    public function hasRole(int $user_id, $role): bool {
        $roles = $this->getUserRoles($user_id);
        $role_name = is_string($role) ? $role : $this->getRoleNameById($role);
        
        foreach ($roles as $user_role) {
            if ($user_role['name'] === $role_name) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * إنشاء دور جديد
     * @param string $name
     * @param string $description
     * @param int|null $parent_id
     * @return int
     */
    public function createRole(string $name, string $description = '', ?int $parent_id = null): int {
        // حساب المستوى
        $level = 0;
        if ($parent_id) {
            $parent = $this->getRoleById($parent_id);
            $level = ($parent['level'] ?? 0) + 1;
        }
        
        $data = [
            'name' => $name,
            'display_name' => $this->generateDisplayName($name),
            'description' => $description,
            'parent_id' => $parent_id,
            'level' => $level
        ];
        
        return $this->db->insert('roles', $data);
    }
    
    /**
     * تحديث دور
     * @param int $role_id
     * @param array $data
     * @return bool
     */
    public function updateRole(int $role_id, array $data): bool {
        $allowed = ['name', 'display_name', 'description', 'parent_id'];
        $update_data = [];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        if (isset($data['parent_id'])) {
            // إعادة حساب المستوى
            $parent = $this->getRoleById($data['parent_id']);
            $update_data['level'] = ($parent['level'] ?? 0) + 1;
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return (bool)$this->db->update('roles', $update_data, ['id' => $role_id]);
    }
    
    /**
     * حذف دور
     * @param int $role_id
     * @return bool
     */
    public function deleteRole(int $role_id): bool {
        return (bool)$this->db->delete('roles', ['id' => $role_id]);
    }
    
    /**
     * الحصول على جميع الأدوار
     * @return array
     */
    public function getAllRoles(): array {
        return $this->db->fetchAll("SELECT * FROM roles ORDER BY level, name");
    }
    
    /**
     * الحصول على دور بالاسم
     * @param string $name
     * @return array|null
     */
    public function getRoleByName(string $name): ?array {
        return $this->db->fetchOne("SELECT * FROM roles WHERE name = ?", [$name]);
    }
    
    /**
     * الحصول على دور بالمعرف
     * @param int $id
     * @return array|null
     */
    public function getRoleById(int $id): ?array {
        return $this->db->fetchOne("SELECT * FROM roles WHERE id = ?", [$id]);
    }
    
    /**
     * الحصول على معرف الدور من الاسم
     * @param string $name
     * @return int|null
     */
    public function getRoleIdByName(string $name): ?int {
        $role = $this->getRoleByName($name);
        return $role ? $role['id'] : null;
    }
    
    /**
     * الحصول على اسم الدور من المعرف
     * @param int $id
     * @return string|null
     */
    public function getRoleNameById(int $id): ?string {
        $role = $this->getRoleById($id);
        return $role ? $role['name'] : null;
    }
    
    // ==========================================
    // دوال إدارة الصلاحيات
    // ==========================================
    
    /**
     * إنشاء صلاحية جديدة
     * @param string $name
     * @param string $resource
     * @param string $action
     * @param string $description
     * @return int
     */
    public function createPermission(string $name, string $resource, string $action, string $description = ''): int {
        $data = [
            'name' => $name,
            'display_name' => $this->generateDisplayName($name),
            'description' => $description,
            'resource' => $resource,
            'action' => $action
        ];
        
        return $this->db->insert('permissions', $data);
    }
    
    /**
     * تحديث صلاحية
     * @param int $permission_id
     * @param array $data
     * @return bool
     */
    public function updatePermission(int $permission_id, array $data): bool {
        $allowed = ['name', 'display_name', 'description', 'resource', 'action', 'conditions'];
        $update_data = [];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return (bool)$this->db->update('permissions', $update_data, ['id' => $permission_id]);
    }
    
    /**
     * حذف صلاحية
     * @param int $permission_id
     * @return bool
     */
    public function deletePermission(int $permission_id): bool {
        return (bool)$this->db->delete('permissions', ['id' => $permission_id]);
    }
    
    /**
     * الحصول على جميع الصلاحيات
     * @return array
     */
    public function getAllPermissions(): array {
        return $this->db->fetchAll("SELECT * FROM permissions ORDER BY resource, action");
    }
    
    /**
     * الحصول على صلاحية بالاسم
     * @param string $name
     * @return array|null
     */
    public function getPermissionByName(string $name): ?array {
        return $this->db->fetchOne("SELECT * FROM permissions WHERE name = ?", [$name]);
    }
    
    /**
     * الحصول على بيانات الصلاحية
     * @param string $permission
     * @return array|null
     */
    public function getPermissionData(string $permission): ?array {
        $cache_key = "perm_data_{$permission}";
        
        if ($this->cache_enabled && isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        $data = $this->getPermissionByName($permission);
        
        if ($this->cache_enabled) {
            $this->cache[$cache_key] = $data;
        }
        
        return $data;
    }
    
    // ==========================================
    // دوال ربط الصلاحيات بالأدوار
    // ==========================================
    
    /**
     * تعيين صلاحية لدور
     * @param int $role_id
     * @param int $permission_id
     * @return bool
     */
    public function assignPermissionToRole(int $role_id, int $permission_id): bool {
        return (bool)$this->db->insert('role_permissions', [
            'role_id' => $role_id,
            'permission_id' => $permission_id
        ]);
    }
    
    /**
     * إزالة صلاحية من دور
     * @param int $role_id
     * @param int $permission_id
     * @return bool
     */
    public function removePermissionFromRole(int $role_id, int $permission_id): bool {
        return (bool)$this->db->delete('role_permissions', [
            'role_id' => $role_id,
            'permission_id' => $permission_id
        ]);
    }
    
    /**
     * الحصول على صلاحيات دور
     * @param int $role_id
     * @return array
     */
    public function getRolePermissions(int $role_id): array {
        $cache_key = "role_perms_{$role_id}";
        
        if ($this->cache_enabled && isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        $permissions = $this->db->fetchAll("
            SELECT p.* FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ", [$role_id]);
        
        if ($this->cache_enabled) {
            $this->cache[$cache_key] = $permissions;
        }
        
        return $permissions;
    }
    
    /**
     * الحصول على صلاحيات المستخدم
     * @param int $user_id
     * @return array
     */
    public function getUserPermissions(int $user_id): array {
        $cache_key = "user_perms_{$user_id}";
        
        if ($this->cache_enabled && isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        $permissions = [];
        
        // صلاحيات الأدوار
        $roles = $this->getUserRoles($user_id);
        
        foreach ($roles as $role) {
            $role_perms = $this->getRolePermissions($role['id']);
            foreach ($role_perms as $perm) {
                $permissions[$perm['name']] = true;
            }
        }
        
        // صلاحيات مباشرة
        $direct_perms = $this->db->fetchAll("
            SELECT p.* FROM permissions p
            JOIN user_permissions up ON p.id = up.permission_id
            WHERE up.user_id = ? AND up.granted = 1
        ", [$user_id]);
        
        foreach ($direct_perms as $perm) {
            $permissions[$perm['name']] = true;
        }
        
        // الصلاحيات الممنوعة (مستثناة)
        $denied_perms = $this->db->fetchAll("
            SELECT p.* FROM permissions p
            JOIN user_permissions up ON p.id = up.permission_id
            WHERE up.user_id = ? AND up.granted = 0
        ", [$user_id]);
        
        foreach ($denied_perms as $perm) {
            unset($permissions[$perm['name']]);
        }
        
        $result = array_keys($permissions);
        
        if ($this->cache_enabled) {
            $this->cache[$cache_key] = $result;
        }
        
        return $result;
    }
    
    // ==========================================
    // دوال وراثة الأدوار
    // ==========================================
    
    /**
     * جعل دور يرث من دور آخر
     * @param int $child_role_id
     * @param int $parent_role_id
     * @return bool
     */
    public function inheritPermissions(int $child_role_id, int $parent_role_id): bool {
        return $this->updateRole($child_role_id, ['parent_id' => $parent_role_id]);
    }
    
    /**
     * الحصول على جميع الأدوار الموروثة لدور
     * @param int $role_id
     * @param array $inherited
     * @return array
     */
    public function getInheritedRoles(int $role_id, array &$inherited = []): array {
        $role = $this->getRoleById($role_id);
        
        if (!$role || !$role['parent_id']) {
            return $inherited;
        }
        
        $parent = $this->getRoleById($role['parent_id']);
        
        if ($parent) {
            $inherited[] = $parent;
            $this->getInheritedRoles($parent['id'], $inherited);
        }
        
        return $inherited;
    }
    
    /**
     * الحصول على جميع الصلاحيات الموروثة لدور
     * @param int $role_id
     * @return array
     */
    public function getInheritedPermissions(int $role_id): array {
        $permissions = [];
        $inherited_roles = $this->getInheritedRoles($role_id);
        
        foreach ($inherited_roles as $role) {
            $role_perms = $this->getRolePermissions($role['id']);
            foreach ($role_perms as $perm) {
                $permissions[$perm['name']] = true;
            }
        }
        
        return array_keys($permissions);
    }
    
    /**
     * الحصول على شجرة الأدوار
     * @param int|null $parent_id
     * @return array
     */
    public function getRoleHierarchy(?int $parent_id = null): array {
        $roles = $this->db->fetchAll(
            "SELECT * FROM roles WHERE parent_id " . ($parent_id ? "= ?" : "IS NULL") . " ORDER BY name",
            $parent_id ? [$parent_id] : []
        );
        
        $result = [];
        
        foreach ($roles as $role) {
            $role['children'] = $this->getRoleHierarchy($role['id']);
            $result[] = $role;
        }
        
        return $result;
    }
    
    // ==========================================
    // دوال التخزين المؤقت
    // ==========================================
    
    /**
     * مسح ذاكرة المستخدم
     * @param int $user_id
     */
    public function clearUserCache(int $user_id): void {
        foreach ($this->cache as $key => $value) {
            if (strpos($key, "user_{$user_id}") !== false || 
                strpos($key, "perm_{$user_id}") !== false) {
                unset($this->cache[$key]);
            }
        }
    }
    
    /**
     * مسح جميع الذاكرة المؤقتة
     */
    public function clearAllCache(): void {
        $this->cache = [];
    }
    
    /**
     * تفعيل/تعطيل التخزين المؤقت
     * @param bool $enabled
     */
    public function setCacheEnabled(bool $enabled): void {
        $this->cache_enabled = $enabled;
        
        if (!$enabled) {
            $this->clearAllCache();
        }
    }
    
    // ==========================================
    // دوال التحقق من الصلاحيات
    // ==========================================
    
    /**
     * التحقق من صحة اسم الصلاحية
     * @param string $name
     * @return bool
     */
    public function validatePermissionName(string $name): bool {
        // الصيغة: resource.action أو resource.sub.action
        return (bool)preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $name);
    }
    
    /**
     * إنشاء اسم عرض من الاسم الداخلي
     * @param string $name
     * @return string
     */
    private function generateDisplayName(string $name): string {
        $parts = explode('.', $name);
        $display = [];
        
        foreach ($parts as $part) {
            $display[] = ucfirst(str_replace('_', ' ', $part));
        }
        
        return implode(' - ', $display);
    }
    
    // ==========================================
    // دوال التصدير والاستيراد
    // ==========================================
    
    /**
     * تصدير جميع الصلاحيات والأدوار
     * @return array
     */
    public function exportPermissions(): array {
        return [
            'roles' => $this->getAllRoles(),
            'permissions' => $this->getAllPermissions(),
            'role_permissions' => $this->db->fetchAll("SELECT * FROM role_permissions"),
            'version' => '1.0',
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * استيراد الصلاحيات والأدوار
     * @param array $data
     * @return bool
     */
    public function importPermissions(array $data): bool {
        try {
            $this->db->beginTransaction();
            
            // استيراد الأدوار
            foreach ($data['roles'] as $role) {
                unset($role['id']);
                $this->createRole($role['name'], $role['description'] ?? '', $role['parent_id'] ?? null);
            }
            
            // استيراد الصلاحيات
            foreach ($data['permissions'] as $permission) {
                unset($permission['id']);
                $this->createPermission(
                    $permission['name'],
                    $permission['resource'] ?? 'general',
                    $permission['action'] ?? 'access',
                    $permission['description'] ?? ''
                );
            }
            
            // استيراد روابط الأدوار والصلاحيات
            foreach ($data['role_permissions'] as $rp) {
                $this->assignPermissionToRole($rp['role_id'], $rp['permission_id']);
            }
            
            $this->db->commit();
            $this->clearAllCache();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }
    
    // ==========================================
    // دوال إحصاءات
    // ==========================================
    
    /**
     * الحصول على إحصاءات النظام
     * @return array
     */
    public function getStatistics(): array {
        $stats = [
            'total_roles' => $this->db->fetchColumn("SELECT COUNT(*) FROM roles"),
            'total_permissions' => $this->db->fetchColumn("SELECT COUNT(*) FROM permissions"),
            'users_with_roles' => $this->db->fetchColumn("SELECT COUNT(DISTINCT user_id) FROM user_roles"),
            'roles_hierarchy' => $this->db->fetchColumn("SELECT COUNT(*) FROM roles WHERE parent_id IS NOT NULL"),
            'permissions_by_resource' => []
        ];
        
        // توزيع الصلاحيات حسب المورد
        $resources = $this->db->fetchAll("
            SELECT resource, COUNT(*) as count 
            FROM permissions 
            GROUP BY resource 
            ORDER BY count DESC
        ");
        
        foreach ($resources as $res) {
            $stats['permissions_by_resource'][$res['resource']] = $res['count'];
        }
        
        return $stats;
    }
}

/**
 * Role
 * @package Permissions
 * 
 * يمثل دور في النظام
 */
class Role {
    
    /**
     * @var array بيانات الدور
     */
    private $data;
    
    /**
     * @var صلاحيات_App|null
     */
    private $permissions_system;
    
    /**
     * المُنشئ
     * @param array $data
     * @param صلاحيات_App|null $permissions_system
     */
    public function __construct(array $data, ?صلاحيات_App $permissions_system = null) {
        $this->data = $data;
        $this->permissions_system = $permissions_system;
    }
    
    /**
     * الحصول على معرف الدور
     * @return int
     */
    public function getId(): int {
        return (int)$this->data['id'];
    }
    
    /**
     * الحصول على اسم الدور
     * @return string
     */
    public function getName(): string {
        return $this->data['name'];
    }
    
    /**
     * الحصول على اسم العرض
     * @return string
     */
    public function getDisplayName(): string {
        return $this->data['display_name'] ?? $this->getName();
    }
    
    /**
     * الحصول على الوصف
     * @return string
     */
    public function getDescription(): string {
        return $this->data['description'] ?? '';
    }
    
    /**
     * الحصول على معرف الدور الأب
     * @return int|null
     */
    public function getParentId(): ?int {
        return $this->data['parent_id'] ?? null;
    }
    
    /**
     * الحصول على المستوى
     * @return int
     */
    public function getLevel(): int {
        return (int)$this->data['level'];
    }
    
    /**
     * التحقق من وجود صلاحية
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool {
        if (!$this->permissions_system) {
            return false;
        }
        
        $permissions = $this->permissions_system->getRolePermissions($this->getId());
        
        foreach ($permissions as $perm) {
            if ($perm['name'] === $permission) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * إضافة صلاحية
     * @param int $permission_id
     */
    public function addPermission(int $permission_id): void {
        if ($this->permissions_system) {
            $this->permissions_system->assignPermissionToRole($this->getId(), $permission_id);
        }
    }
    
    /**
     * إزالة صلاحية
     * @param int $permission_id
     */
    public function removePermission(int $permission_id): void {
        if ($this->permissions_system) {
            $this->permissions_system->removePermissionFromRole($this->getId(), $permission_id);
        }
    }
    
    /**
     * الوراثة من دور
     * @param Role $parent
     */
    public function inheritFrom(Role $parent): void {
        if ($this->permissions_system) {
            $this->permissions_system->inheritPermissions($this->getId(), $parent->getId());
        }
    }
    
    /**
     * الحصول على جميع الصلاحيات (بما فيها الموروثة)
     * @return array
     */
    public function getAllPermissions(): array {
        if (!$this->permissions_system) {
            return [];
        }
        
        $direct = $this->permissions_system->getRolePermissions($this->getId());
        $inherited = $this->permissions_system->getInheritedPermissions($this->getId());
        
        return array_merge($direct, $inherited);
    }
    
    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array {
        return $this->data;
    }
}

/**
 * Permission
 * @package Permissions
 * 
 * تمثل صلاحية في النظام
 */
class Permission {
    
    /**
     * @var array بيانات الصلاحية
     */
    private $data;
    
    /**
     * المُنشئ
     * @param array $data
     */
    public function __construct(array $data) {
        $this->data = $data;
    }
    
    /**
     * الحصول على معرف الصلاحية
     * @return int
     */
    public function getId(): int {
        return (int)$this->data['id'];
    }
    
    /**
     * الحصول على اسم الصلاحية
     * @return string
     */
    public function getName(): string {
        return $this->data['name'];
    }
    
    /**
     * الحصول على اسم العرض
     * @return string
     */
    public function getDisplayName(): string {
        return $this->data['display_name'] ?? $this->getName();
    }
    
    /**
     * الحصول على الوصف
     * @return string
     */
    public function getDescription(): string {
        return $this->data['description'] ?? '';
    }
    
    /**
     * الحصول على المورد
     * @return string
     */
    public function getResource(): string {
        return $this->data['resource'] ?? 'general';
    }
    
    /**
     * الحصول على الإجراء
     * @return string
     */
    public function getAction(): string {
        return $this->data['action'] ?? 'access';
    }
    
    /**
     * التحقق من إمكانية الوصول لمورد وإجراء
     * @param string $resource
     * @param string $action
     * @return bool
     */
    public function canAccess(string $resource, string $action): bool {
        return $this->getResource() === $resource && $this->getAction() === $action;
    }
    
    /**
     * إضافة شرط
     * @param array $condition
     */
    public function addCondition(array $condition): void {
        $conditions = json_decode($this->data['conditions'] ?? '[]', true);
        $conditions[] = $condition;
        $this->data['conditions'] = json_encode($conditions);
    }
    
    /**
     * الحصول على الشروط
     * @return array
     */
    public function getConditions(): array {
        return json_decode($this->data['conditions'] ?? '[]', true);
    }
    
    /**
     * تقييم الشروط
     * @param int $user_id
     * @param mixed $resource_id
     * @return bool
     */
    public function evaluateConditions(int $user_id, $resource_id): bool {
        $conditions = $this->getConditions();
        
        foreach ($conditions as $condition) {
            // تقييم الشرط حسب نوعه
            // يمكن استدعاء دوال من صلاحيات_App
        }
        
        return true;
    }
    
    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array {
        return $this->data;
    }
}

/**
 * User_Role
 * @package Permissions
 * 
 * تمثل دور المستخدم
 */
class User_Role {
    
    /**
     * @var array بيانات علاقة المستخدم بالدور
     */
    private $data;
    
    /**
     * المُنشئ
     * @param array $data
     */
    public function __construct(array $data) {
        $this->data = $data;
    }
    
    /**
     * الحصول على معرف المستخدم
     * @return int
     */
    public function getUserId(): int {
        return (int)$this->data['user_id'];
    }
    
    /**
     * الحصول على معرف الدور
     * @return int
     */
    public function getRoleId(): int {
        return (int)$this->data['role_id'];
    }
    
    /**
     * الحصول على المعين
     * @return int|null
     */
    public function getAssignedBy(): ?int {
        return isset($this->data['assigned_by']) ? (int)$this->data['assigned_by'] : null;
    }
    
    /**
     * الحصول على وقت التعيين
     * @return string
     */
    public function getAssignedAt(): string {
        return $this->data['assigned_at'];
    }
    
    /**
     * الحصول على وقت الانتهاء
     * @return string|null
     */
    public function getExpiresAt(): ?string {
        return $this->data['expires_at'] ?? null;
    }
    
    /**
     * التحقق من النشاط
     * @return bool
     */
    public function isActive(): bool {
        if (!$this->getExpiresAt()) {
            return true;
        }
        
        return strtotime($this->getExpiresAt()) > time();
    }
    
    /**
     * تمديد الانتهاء
     * @param int $days
     */
    public function extendExpiry(int $days): void {
        $expires = $this->getExpiresAt();
        
        if ($expires) {
            $new_expires = date('Y-m-d H:i:s', strtotime($expires . " + {$days} days"));
            // تحديث في قاعدة البيانات
        }
    }
}

/**
 * Permission_Resource
 * @package Permissions
 * 
 * تمثل مورد الصلاحيات
 */
class Permission_Resource {
    
    /**
     * @var string اسم المورد
     */
    private $name;
    
    /**
     * @var array الإجراءات المسموحة
     */
    private $actions;
    
    /**
     * @var callable|null دالة التحقق من الملكية
     */
    private $owner_check;
    
    /**
     * المُنشئ
     * @param string $name
     * @param array $actions
     * @param callable|null $owner_check
     */
    public function __construct(string $name, array $actions = [], ?callable $owner_check = null) {
        $this->name = $name;
        $this->actions = $actions;
        $this->owner_check = $owner_check;
    }
    
    /**
     * الحصول على اسم المورد
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * الحصول على الإجراءات
     * @return array
     */
    public function getActions(): array {
        return $this->actions;
    }
    
    /**
     * إضافة إجراء
     * @param string $action
     */
    public function addAction(string $action): void {
        if (!in_array($action, $this->actions)) {
            $this->actions[] = $action;
        }
    }
    
    /**
     * التحقق من وجود إجراء
     * @param string $action
     * @return bool
     */
    public function hasAction(string $action): bool {
        return in_array($action, $this->actions);
    }
    
    /**
     * التحقق من إمكانية الوصول
     * @param int $user_id
     * @param string $action
     * @param mixed $resource_id
     * @return bool
     */
    public function can(int $user_id, string $action, $resource_id = null): bool {
        if (!$this->hasAction($action)) {
            return false;
        }
        
        if ($resource_id !== null && $this->owner_check) {
            return call_user_func($this->owner_check, $user_id, $resource_id);
        }
        
        return true;
    }
}

// ==========================================
// دوال مساعدة للتحقق من الصلاحيات
// ==========================================

/**
 * دالة مساعدة للتحقق السريع من الصلاحية
 * @param string $permission
 * @param mixed $resource_id
 * @return bool
 */
function can(string $permission, $resource_id = null): bool {
    $main = Main_App::getInstance();
    
    if (!$main->auth || !$main->auth->check()) {
        return false;
    }
    
    return $main->permissions->checkPermission(
        $main->auth->id(),
        $permission,
        $resource_id
    );
}

/**
 * دالة مساعدة للتحقق من الدور
 * @param string $role
 * @return bool
 */
function hasRole(string $role): bool {
    $main = Main_App::getInstance();
    
    if (!$main->auth || !$main->auth->check()) {
        return false;
    }
    
    return $main->permissions->hasRole($main->auth->id(), $role);
}

?>