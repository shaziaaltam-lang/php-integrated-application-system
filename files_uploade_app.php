<?php
/**
 * files_uploade_app.php
 * @version 1.0.0
 * @package File Upload
 * 
 * نظام رفع وإدارة الملفات المتكامل
 * يدعم رفع الملفات، الصور، المستندات، مع التحقق من الأنواع والأحجام
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__FILE__));
}

/**
 * Files_Upload_App
 * @package File Upload
 * 
 * الكلاس الرئيسي لنظام رفع الملفات
 */
class Files_Upload_App {
    
    // ==========================================
    // الخصائص
    // ==========================================
    
    /**
     * @var array إعدادات رفع الملفات
     */
    private $config = [
        'upload_dir' => 'uploads',
        'max_size' => 5242880, // 5MB افتراضياً
        'allowed_types' => [],
        'allowed_extensions' => [],
        'create_thumbnails' => false,
        'thumbnail_size' => [200, 200],
        'watermark' => false,
        'watermark_image' => '',
        'encrypt_filename' => true,
        'preserve_original' => false,
        'max_files' => 10,
        'min_files' => 1,
        'overwrite' => false,
        'date_subfolder' => true,
        'random_subfolder' => false
    ];
    
    /**
     * @var array أنواع الملفات المسموحة الافتراضية
     */
    private $default_allowed_types = [
        // الصور
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml',
        // المستندات
        'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv', 'text/rtf',
        // الأرشيف
        'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
        // الصوت والفيديو
        'audio/mpeg', 'audio/wav', 'video/mp4', 'video/mpeg', 'video/quicktime'
    ];
    
    /**
     * @var array امتدادات الملفات المسموحة الافتراضية
     */
    private $default_allowed_extensions = [
        // الصور
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
        // المستندات
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf',
        // الأرشيف
        'zip', 'rar', '7z',
        // الصوت والفيديو
        'mp3', 'wav', 'mp4', 'mpeg', 'mov', 'avi'
    ];
    
    /**
     * @var array الأخطاء التي حدثت أثناء الرفع
     */
    private $errors = [];
    
    /**
     * @var array الملفات المرفوعة بنجاح
     */
    private $uploaded_files = [];
    
    /**
     * @var array معلومات الملف الحالي
     */
    private $current_file = [];
    
    /**
     * @var resource|GDImage مورد الصورة للمعالجة
     */
    private $image_resource = null;
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->config, $config);
        
        // تعيين الأنواع المسموحة الافتراضية إذا لم يتم تحديدها
        if (empty($this->config['allowed_types'])) {
            $this->config['allowed_types'] = $this->default_allowed_types;
        }
        
        if (empty($this->config['allowed_extensions'])) {
            $this->config['allowed_extensions'] = $this->default_allowed_extensions;
        }
        
        // إنشاء مجلد الرفع إذا لم يكن موجوداً
        $this->createUploadDirectory();
    }
    
    // ==========================================
    // دوال رفع الملفات
    // ==========================================
    
    /**
     * رفع ملف واحد
     * @param array $file عنصر الملف من $_FILES
     * @param string $subfolder مجلد فرعي اختياري
     * @return array|false معلومات الملف المرفوع أو false عند الفشل
     */
    public function upload($file, string $subfolder = '') {
        $this->errors = [];
        $this->current_file = $file;
        
        // التحقق من صحة الملف
        if (!$this->validateFile($file)) {
            return false;
        }
        
        // تحديد مسار الحفظ
        $uploadPath = $this->getUploadPath($subfolder);
        $filename = $this->getFilename($file['name']);
        $filepath = $uploadPath . '/' . $filename;
        
        // رفع الملف
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // معالجة الصور إذا لزم الأمر
            if ($this->isImage($file['type']) && $this->config['create_thumbnails']) {
                $this->createThumbnail($filepath);
            }
            
            if ($this->isImage($file['type']) && $this->config['watermark'] && !empty($this->config['watermark_image'])) {
                $this->applyWatermark($filepath);
            }
            
            // تجميع معلومات الملف
            $fileInfo = [
                'original_name' => $file['name'],
                'filename' => $filename,
                'filepath' => $filepath,
                'url' => $this->getFileUrl($filepath),
                'size' => $file['size'],
                'type' => $file['type'],
                'extension' => pathinfo($file['name'], PATHINFO_EXTENSION),
                'uploaded_at' => date('Y-m-d H:i:s')
            ];
            
            $this->uploaded_files[] = $fileInfo;
            
            return $fileInfo;
        } else {
            $this->errors[] = 'فشل رفع الملف: ' . $file['name'];
            return false;
        }
    }
    
    /**
     * رفع عدة ملفات
     * @param array $files مصفوفة الملفات من $_FILES
     * @param string $subfolder مجلد فرعي اختياري
     * @return array الملفات المرفوعة بنجاح
     */
    public function uploadMultiple(array $files, string $subfolder = '') {
        $uploaded = [];
        $this->errors = [];
        
        // التحقق من عدد الملفات
        $fileCount = count($files['name']);
        if ($fileCount < $this->config['min_files']) {
            $this->errors[] = 'عدد الملفات أقل من الحد الأدنى المطلوب: ' . $this->config['min_files'];
            return [];
        }
        
        if ($fileCount > $this->config['max_files']) {
            $this->errors[] = 'عدد الملفات أكبر من الحد الأقصى المسموح: ' . $this->config['max_files'];
            return [];
        }
        
        // معالجة كل ملف
        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];
            
            $result = $this->upload($file, $subfolder);
            
            if ($result) {
                $uploaded[] = $result;
            }
        }
        
        return $uploaded;
    }
    
    /**
     * رفع صورة مع خيارات معالجة إضافية
     * @param array $file عنصر الملف من $_FILES
     * @param array $options خيارات معالجة الصورة
     * @return array|false
     */
    public function uploadImage($file, array $options = []) {
        if (!$this->isImage($file['type'])) {
            $this->errors[] = 'الملف ليس صورة صالحة';
            return false;
        }
        
        $result = $this->upload($file, $options['subfolder'] ?? 'images');
        
        if ($result && !empty($options)) {
            $this->processImage($result['filepath'], $options);
        }
        
        return $result;
    }
    
    // ==========================================
    // دوال التحقق
    // ==========================================
    
    /**
     * التحقق من صحة الملف
     * @param array $file
     * @return bool
     */
    private function validateFile($file): bool {
        // التحقق من وجود أخطاء في الرفع
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->handleUploadError($file['error']);
            return false;
        }
        
        // التحقق من حجم الملف
        if ($file['size'] > $this->config['max_size']) {
            $maxSizeMB = round($this->config['max_size'] / 1048576, 2);
            $this->errors[] = "حجم الملف كبير جداً. الحد الأقصى: {$maxSizeMB}MB";
            return false;
        }
        
        // التحقق من نوع الملف
        if (!in_array($file['type'], $this->config['allowed_types'])) {
            $this->errors[] = "نوع الملف غير مسموح: {$file['type']}";
            return false;
        }
        
        // التحقق من الامتداد
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->config['allowed_extensions'])) {
            $this->errors[] = "امتداد الملف غير مسموح: {$extension}";
            return false;
        }
        
        return true;
    }
    
    /**
     * معالجة أخطاء رفع الملفات
     * @param int $errorCode
     */
    private function handleUploadError(int $errorCode): void {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $this->errors[] = 'حجم الملف أكبر من المسموح به';
                break;
            case UPLOAD_ERR_PARTIAL:
                $this->errors[] = 'تم رفع جزء فقط من الملف';
                break;
            case UPLOAD_ERR_NO_FILE:
                $this->errors[] = 'لم يتم رفع أي ملف';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $this->errors[] = 'مجلد الملفات المؤقتة غير موجود';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $this->errors[] = 'فشل في كتابة الملف على القرص';
                break;
            case UPLOAD_ERR_EXTENSION:
                $this->errors[] = 'تم إيقاف رفع الملف بواسطة امتداد PHP';
                break;
            default:
                $this->errors[] = 'خطأ غير معروف في رفع الملف';
        }
    }
    
    // ==========================================
    // دوال معالجة الصور
    // ==========================================
    
    /**
     * التحقق مما إذا كان الملف صورة
     * @param string $mimeType
     * @return bool
     */
    private function isImage(string $mimeType): bool {
        return strpos($mimeType, 'image/') === 0;
    }
    
    /**
     * إنشاء صورة مصغرة (Thumbnail)
     * @param string $filepath
     * @return bool
     */
    private function createThumbnail(string $filepath): bool {
        $thumbDir = dirname($filepath) . '/thumbnails';
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }
        
        $thumbPath = $thumbDir . '/' . basename($filepath);
        
        // تحميل الصورة حسب النوع
        $imageInfo = getimagesize($filepath);
        if (!$imageInfo) {
            return false;
        }
        
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($filepath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($filepath);
                break;
            default:
                return false;
        }
        
        // حساب الأبعاد الجديدة
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $thumbWidth = $this->config['thumbnail_size'][0];
        $thumbHeight = $this->config['thumbnail_size'][1];
        
        // الحفاظ على نسبة العرض إلى الارتفاع
        $ratio = $width / $height;
        if ($thumbWidth / $thumbHeight > $ratio) {
            $thumbWidth = (int)($thumbHeight * $ratio);
        } else {
            $thumbHeight = (int)($thumbWidth / $ratio);
        }
        
        // إنشاء الصورة المصغرة
        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        
        // الحفاظ على الشفافية للصور PNG
        if ($imageInfo[2] == IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
            imagefilledrectangle($thumb, 0, 0, $thumbWidth, $thumbHeight, $transparent);
        }
        
        // تغيير الحجم
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
        
        // حفظ الصورة المصغرة
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                imagejpeg($thumb, $thumbPath, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($thumb, $thumbPath, 9);
                break;
            case IMAGETYPE_GIF:
                imagegif($thumb, $thumbPath);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($thumb, $thumbPath, 85);
                break;
        }
        
        // تنظيف الذاكرة
        imagedestroy($source);
        imagedestroy($thumb);
        
        return true;
    }
    
    /**
     * تطبيق علامة مائية على الصورة
     * @param string $filepath
     * @return bool
     */
    private function applyWatermark(string $filepath): bool {
        $watermarkPath = $this->config['watermark_image'];
        
        if (!file_exists($watermarkPath)) {
            return false;
        }
        
        // تحميل الصورة الرئيسية
        $imageInfo = getimagesize($filepath);
        if (!$imageInfo) {
            return false;
        }
        
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($filepath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($filepath);
                break;
            default:
                return false;
        }
        
        // تحميل العلامة المائية
        $watermarkInfo = getimagesize($watermarkPath);
        switch ($watermarkInfo[2]) {
            case IMAGETYPE_PNG:
                $watermark = imagecreatefrompng($watermarkPath);
                break;
            case IMAGETYPE_JPEG:
                $watermark = imagecreatefromjpeg($watermarkPath);
                break;
            default:
                imagedestroy($source);
                return false;
        }
        
        // حساب موقع العلامة المائية (أسفل اليمين)
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $watermarkWidth = imagesx($watermark);
        $watermarkHeight = imagesy($watermark);
        
        $destX = $sourceWidth - $watermarkWidth - 10;
        $destY = $sourceHeight - $watermarkHeight - 10;
        
        // تطبيق العلامة المائية
        imagecopy($source, $watermark, $destX, $destY, 0, 0, $watermarkWidth, $watermarkHeight);
        
        // حفظ الصورة
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                imagejpeg($source, $filepath, 90);
                break;
            case IMAGETYPE_PNG:
                imagepng($source, $filepath, 9);
                break;
            case IMAGETYPE_GIF:
                imagegif($source, $filepath);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($source, $filepath, 90);
                break;
        }
        
        // تنظيف الذاكرة
        imagedestroy($source);
        imagedestroy($watermark);
        
        return true;
    }
    
    /**
     * معالجة الصورة (تغيير الحجم، قص، تدوير)
     * @param string $filepath
     * @param array $options
     * @return bool
     */
    public function processImage(string $filepath, array $options): bool {
        if (!file_exists($filepath)) {
            $this->errors[] = 'الملف غير موجود';
            return false;
        }
        
        $imageInfo = getimagesize($filepath);
        if (!$imageInfo) {
            $this->errors[] = 'الملف ليس صورة صالحة';
            return false;
        }
        
        // تحميل الصورة
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($filepath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($filepath);
                break;
            default:
                return false;
        }
        
        // تغيير الحجم
        if (isset($options['resize'])) {
            $width = $options['resize']['width'] ?? imagesx($source);
            $height = $options['resize']['height'] ?? imagesy($source);
            
            $newImage = imagecreatetruecolor($width, $height);
            
            // الحفاظ على الشفافية
            if ($imageInfo[2] == IMAGETYPE_PNG) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $width, $height, $transparent);
            }
            
            imagecopyresampled($newImage, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
            imagedestroy($source);
            $source = $newImage;
        }
        
        // قص الصورة
        if (isset($options['crop'])) {
            $x = $options['crop']['x'] ?? 0;
            $y = $options['crop']['y'] ?? 0;
            $width = $options['crop']['width'] ?? imagesx($source);
            $height = $options['crop']['height'] ?? imagesy($source);
            
            $cropped = imagecreatetruecolor($width, $height);
            
            if ($imageInfo[2] == IMAGETYPE_PNG) {
                imagealphablending($cropped, false);
                imagesavealpha($cropped, true);
                $transparent = imagecolorallocatealpha($cropped, 255, 255, 255, 127);
                imagefilledrectangle($cropped, 0, 0, $width, $height, $transparent);
            }
            
            imagecopy($cropped, $source, 0, 0, $x, $y, $width, $height);
            imagedestroy($source);
            $source = $cropped;
        }
        
        // تدوير الصورة
        if (isset($options['rotate'])) {
            $angle = $options['rotate'];
            $source = imagerotate($source, $angle, 0);
        }
        
        // حفظ الصورة
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                imagejpeg($source, $filepath, $options['quality'] ?? 90);
                break;
            case IMAGETYPE_PNG:
                imagepng($source, $filepath, $options['quality'] ?? 9);
                break;
            case IMAGETYPE_GIF:
                imagegif($source, $filepath);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($source, $filepath, $options['quality'] ?? 90);
                break;
        }
        
        imagedestroy($source);
        
        return true;
    }
    
    // ==========================================
    // دوال إدارة الملفات
    // ==========================================
    
    /**
     * حذف ملف
     * @param string $filepath
     * @return bool
     */
    public function deleteFile(string $filepath): bool {
        if (file_exists($filepath)) {
            // حذف الصورة المصغرة إذا وجدت
            $thumbPath = dirname($filepath) . '/thumbnails/' . basename($filepath);
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }
            
            return unlink($filepath);
        }
        
        return false;
    }
    
    /**
     * حذف عدة ملفات
     * @param array $filepaths
     * @return array
     */
    public function deleteMultiple(array $filepaths): array {
        $results = [];
        
        foreach ($filepaths as $filepath) {
            $results[$filepath] = $this->deleteFile($filepath);
        }
        
        return $results;
    }
    
    /**
     * نسخ ملف
     * @param string $source
     * @param string $destination
     * @return bool
     */
    public function copyFile(string $source, string $destination): bool {
        if (!file_exists($source)) {
            $this->errors[] = 'الملف المصدر غير موجود';
            return false;
        }
        
        $destDir = dirname($destination);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        
        return copy($source, $destination);
    }
    
    /**
     * نقل ملف
     * @param string $source
     * @param string $destination
     * @return bool
     */
    public function moveFile(string $source, string $destination): bool {
        if ($this->copyFile($source, $destination)) {
            return $this->deleteFile($source);
        }
        
        return false;
    }
    
    /**
     * الحصول على معلومات الملف
     * @param string $filepath
     * @return array|null
     */
    public function getFileInfo(string $filepath): ?array {
        if (!file_exists($filepath)) {
            return null;
        }
        
        $info = pathinfo($filepath);
        
        return [
            'basename' => $info['basename'],
            'filename' => $info['filename'],
            'extension' => $info['extension'] ?? '',
            'dirname' => $info['dirname'],
            'size' => filesize($filepath),
            'size_formatted' => $this->formatFileSize(filesize($filepath)),
            'mime_type' => mime_content_type($filepath),
            'last_modified' => filemtime($filepath),
            'is_image' => $this->isImage(mime_content_type($filepath)),
            'permissions' => substr(sprintf('%o', fileperms($filepath)), -4)
        ];
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * إنشاء مجلد الرفع إذا لم يكن موجوداً
     */
    private function createUploadDirectory(): void {
        if (!is_dir($this->config['upload_dir'])) {
            mkdir($this->config['upload_dir'], 0755, true);
        }
    }
    
    /**
     * الحصول على مسار الحفظ الكامل
     * @param string $subfolder
     * @return string
     */
    private function getUploadPath(string $subfolder = ''): string {
        $path = $this->config['upload_dir'];
        
        if (!empty($subfolder)) {
            $path .= '/' . trim($subfolder, '/');
        }
        
        if ($this->config['date_subfolder']) {
            $path .= '/' . date('Y/m/d');
        }
        
        if ($this->config['random_subfolder']) {
            $path .= '/' . bin2hex(random_bytes(4));
        }
        
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        
        return $path;
    }
    
    /**
     * الحصول على اسم الملف
     * @param string $originalName
     * @return string
     */
    private function getFilename(string $originalName): string {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        
        if ($this->config['encrypt_filename']) {
            $filename = uniqid() . '_' . bin2hex(random_bytes(8));
        } else {
            // تنظيف اسم الملف
            $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '', pathinfo($originalName, PATHINFO_FILENAME));
            $filename = str_replace(' ', '_', $filename);
            
            if (empty($filename)) {
                $filename = 'file_' . uniqid();
            }
        }
        
        // التحقق من عدم وجود ملف بنفس الاسم
        if (!$this->config['overwrite']) {
            $filepath = $this->getUploadPath() . '/' . $filename . '.' . $extension;
            $counter = 1;
            
            while (file_exists($filepath)) {
                $newFilename = $filename . '_' . $counter;
                $filepath = $this->getUploadPath() . '/' . $newFilename . '.' . $extension;
                $counter++;
            }
            
            $filename = isset($newFilename) ? $newFilename : $filename;
        }
        
        return $filename . '.' . $extension;
    }
    
    /**
     * الحصول على رابط الملف
     * @param string $filepath
     * @return string
     */
    private function getFileUrl(string $filepath): string {
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        
        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        }
        
        $relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $filepath);
        
        return $baseUrl . $relativePath;
    }
    
    /**
     * تنسيق حجم الملف
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    public function formatFileSize(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * الحصول على الأخطاء
     * @return array
     */
    public function getErrors(): array {
        return $this->errors;
    }
    
    /**
     * الحصول على آخر خطأ
     * @return string|null
     */
    public function getLastError(): ?string {
        return !empty($this->errors) ? end($this->errors) : null;
    }
    
    /**
     * الحصول على الملفات المرفوعة
     * @return array
     */
    public function getUploadedFiles(): array {
        return $this->uploaded_files;
    }
    
    /**
     * تنظيف الملفات المؤقتة القديمة
     * @param int $hours عدد الساعات
     * @return int
     */
    public function cleanTempFiles(int $hours = 24): int {
        $tempDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        $count = 0;
        $timeLimit = time() - ($hours * 3600);
        
        $files = glob($tempDir . '/php*');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $timeLimit) {
                unlink($file);
                $count++;
            }
        }
        
        return $count;
    }
}

/**
 * File_Validator
 * @package File Upload
 * 
 * التحقق من صحة الملفات
 */
class File_Validator {
    
    /**
     * @var array
     */
    private $errors = [];
    
    /**
     * التحقق من صحة الصورة
     * @param string $filepath
     * @return bool
     */
    public function validateImage(string $filepath): bool {
        if (!file_exists($filepath)) {
            $this->errors[] = 'الملف غير موجود';
            return false;
        }
        
        $imageInfo = getimagesize($filepath);
        
        if ($imageInfo === false) {
            $this->errors[] = 'الملف ليس صورة صالحة';
            return false;
        }
        
        return true;
    }
    
    /**
     * التحقق من أبعاد الصورة
     * @param string $filepath
     * @param int $minWidth
     * @param int $minHeight
     * @param int|null $maxWidth
     * @param int|null $maxHeight
     * @return bool
     */
    public function validateImageDimensions(string $filepath, int $minWidth, int $minHeight, ?int $maxWidth = null, ?int $maxHeight = null): bool {
        $imageInfo = getimagesize($filepath);
        
        if (!$imageInfo) {
            $this->errors[] = 'لا يمكن قراءة أبعاد الصورة';
            return false;
        }
        
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        if ($width < $minWidth || $height < $minHeight) {
            $this->errors[] = "أبعاد الصورة صغيرة جداً. الحد الأدنى: {$minWidth}x{$minHeight}";
            return false;
        }
        
        if ($maxWidth !== null && $width > $maxWidth) {
            $this->errors[] = "عرض الصورة كبير جداً. الحد الأقصى: {$maxWidth}";
            return false;
        }
        
        if ($maxHeight !== null && $height > $maxHeight) {
            $this->errors[] = "ارتفاع الصورة كبير جداً. الحد الأقصى: {$maxHeight}";
            return false;
        }
        
        return true;
    }
    
    /**
     * التحقق من نوع MIME الفعلي
     * @param string $filepath
     * @param string $expectedType
     * @return bool
     */
    public function validateMimeType(string $filepath, string $expectedType): bool {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filepath);
        finfo_close($finfo);
        
        if ($mimeType !== $expectedType) {
            $this->errors[] = "نوع الملف الفعلي ({$mimeType}) لا يطابق النوع المتوقع ({$expectedType})";
            return false;
        }
        
        return true;
    }
    
    /**
     * فحص الملف بحثاً عن الفيروسات (يمكن ربطها مع ClamAV)
     * @param string $filepath
     * @return bool
     */
    public function scanForVirus(string $filepath): bool {
        // يمكن إضافة تكامل مع ClamAV هنا
        return true;
    }
    
    /**
     * الحصول على الأخطاء
     * @return array
     */
    public function getErrors(): array {
        return $this->errors;
    }
}

/**
 * Image_Manipulator
 * @package File Upload
 * 
 * معالجة وتحرير الصور
 */
class Image_Manipulator {
    
    /**
     * @var resource|GDImage
     */
    private $image;
    
    /**
     * @var int
     */
    private $width;
    
    /**
     * @var int
     */
    private $height;
    
    /**
     * @var string
     */
    private $type;
    
    /**
     * المُنشئ
     * @param string $filepath
     * @throws Exception
     */
    public function __construct(string $filepath) {
        $this->load($filepath);
    }
    
    /**
     * تحميل الصورة
     * @param string $filepath
     * @throws Exception
     */
    public function load(string $filepath): void {
        if (!file_exists($filepath)) {
            throw new Exception('ملف الصورة غير موجود');
        }
        
        $imageInfo = getimagesize($filepath);
        
        if (!$imageInfo) {
            throw new Exception('الملف ليس صورة صالحة');
        }
        
        $this->width = $imageInfo[0];
        $this->height = $imageInfo[1];
        $this->type = $imageInfo[2];
        
        switch ($this->type) {
            case IMAGETYPE_JPEG:
                $this->image = imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $this->image = imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_GIF:
                $this->image = imagecreatefromgif($filepath);
                break;
            case IMAGETYPE_WEBP:
                $this->image = imagecreatefromwebp($filepath);
                break;
            default:
                throw new Exception('نوع الصورة غير مدعوم');
        }
    }
    
    /**
     * تغيير حجم الصورة
     * @param int $width
     * @param int $height
     * @param bool $maintainAspect
     * @return self
     */
    public function resize(int $width, int $height, bool $maintainAspect = true): self {
        if ($maintainAspect) {
            $ratio = $this->width / $this->height;
            
            if ($width / $height > $ratio) {
                $width = (int)($height * $ratio);
            } else {
                $height = (int)($width / $ratio);
            }
        }
        
        $newImage = imagecreatetruecolor($width, $height);
        
        // الحفاظ على الشفافية
        if ($this->type == IMAGETYPE_PNG) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $width, $height, $transparent);
        }
        
        imagecopyresampled($newImage, $this->image, 0, 0, 0, 0, $width, $height, $this->width, $this->height);
        
        imagedestroy($this->image);
        $this->image = $newImage;
        $this->width = $width;
        $this->height = $height;
        
        return $this;
    }
    
    /**
     * قص الصورة
     * @param int $x
     * @param int $y
     * @param int $width
     * @param int $height
     * @return self
     */
    public function crop(int $x, int $y, int $width, int $height): self {
        $newImage = imagecreatetruecolor($width, $height);
        
        if ($this->type == IMAGETYPE_PNG) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
        }
        
        imagecopy($newImage, $this->image, 0, 0, $x, $y, $width, $height);
        
        imagedestroy($this->image);
        $this->image = $newImage;
        $this->width = $width;
        $this->height = $height;
        
        return $this;
    }
    
    /**
     * تدوير الصورة
     * @param float $angle
     * @param int $bgColor
     * @return self
     */
    public function rotate(float $angle, int $bgColor = 0): self {
        $this->image = imagerotate($this->image, $angle, $bgColor);
        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
        
        return $this;
    }
    
    /**
     * إضافة نص على الصورة
     * @param string $text
     * @param array $options
     * @return self
     */
    public function addText(string $text, array $options = []): self {
        $fontSize = $options['size'] ?? 20;
        $angle = $options['angle'] ?? 0;
        $x = $options['x'] ?? 10;
        $y = $options['y'] ?? 10;
        $color = $options['color'] ?? [0, 0, 0];
        $fontFile = $options['font'] ?? __DIR__ . '/fonts/arial.ttf';
        
        $textColor = imagecolorallocate($this->image, $color[0], $color[1], $color[2]);
        
        if (file_exists($fontFile)) {
            imagettftext($this->image, $fontSize, $angle, $x, $y, $textColor, $fontFile, $text);
        } else {
            imagestring($this->image, 5, $x, $y, $text, $textColor);
        }
        
        return $this;
    }
    
    /**
     * تطبيق مرشح
     * @param int $filter
     * @param array $args
     * @return self
     */
    public function applyFilter(int $filter, array $args = []): self {
        switch ($filter) {
            case IMG_FILTER_GRAYSCALE:
                imagefilter($this->image, IMG_FILTER_GRAYSCALE);
                break;
            case IMG_FILTER_BRIGHTNESS:
                $level = $args[0] ?? 50;
                imagefilter($this->image, IMG_FILTER_BRIGHTNESS, $level);
                break;
            case IMG_FILTER_CONTRAST:
                $level = $args[0] ?? -50;
                imagefilter($this->image, IMG_FILTER_CONTRAST, $level);
                break;
            case IMG_FILTER_COLORIZE:
                $red = $args[0] ?? 0;
                $green = $args[1] ?? 0;
                $blue = $args[2] ?? 0;
                imagefilter($this->image, IMG_FILTER_COLORIZE, $red, $green, $blue);
                break;
            case IMG_FILTER_EDGEDETECT:
                imagefilter($this->image, IMG_FILTER_EDGEDETECT);
                break;
            case IMG_FILTER_EMBOSS:
                imagefilter($this->image, IMG_FILTER_EMBOSS);
                break;
            case IMG_FILTER_GAUSSIAN_BLUR:
                imagefilter($this->image, IMG_FILTER_GAUSSIAN_BLUR);
                break;
            case IMG_FILTER_SELECTIVE_BLUR:
                imagefilter($this->image, IMG_FILTER_SELECTIVE_BLUR);
                break;
            case IMG_FILTER_MEAN_REMOVAL:
                imagefilter($this->image, IMG_FILTER_MEAN_REMOVAL);
                break;
            case IMG_FILTER_SMOOTH:
                $level = $args[0] ?? 5;
                imagefilter($this->image, IMG_FILTER_SMOOTH, $level);
                break;
            case IMG_FILTER_PIXELATE:
                $size = $args[0] ?? 5;
                $mode = $args[1] ?? true;
                imagefilter($this->image, IMG_FILTER_PIXELATE, $size, $mode);
                break;
        }
        
        return $this;
    }
    
    /**
     * حفظ الصورة
     * @param string $filepath
     * @param int $quality
     * @return bool
     */
    public function save(string $filepath, int $quality = 90): bool {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        switch ($this->type) {
            case IMAGETYPE_JPEG:
                return imagejpeg($this->image, $filepath, $quality);
            case IMAGETYPE_PNG:
                $pngQuality = 9 - (int)($quality / 10);
                return imagepng($this->image, $filepath, $pngQuality);
            case IMAGETYPE_GIF:
                return imagegif($this->image, $filepath);
            case IMAGETYPE_WEBP:
                return imagewebp($this->image, $filepath, $quality);
            default:
                return false;
        }
    }
    
    /**
     * إخراج الصورة مباشرة
     */
    public function output(): void {
        switch ($this->type) {
            case IMAGETYPE_JPEG:
                header('Content-Type: image/jpeg');
                imagejpeg($this->image);
                break;
            case IMAGETYPE_PNG:
                header('Content-Type: image/png');
                imagepng($this->image);
                break;
            case IMAGETYPE_GIF:
                header('Content-Type: image/gif');
                imagegif($this->image);
                break;
            case IMAGETYPE_WEBP:
                header('Content-Type: image/webp');
                imagewebp($this->image);
                break;
        }
    }
    
    /**
     * الحصول على عرض الصورة
     * @return int
     */
    public function getWidth(): int {
        return $this->width;
    }
    
    /**
     * الحصول على ارتفاع الصورة
     * @return int
     */
    public function getHeight(): int {
        return $this->height;
    }
    
    /**
     * التدمير - تنظيف الذاكرة
     */
    public function __destruct() {
        if ($this->image) {
            imagedestroy($this->image);
        }
    }
}

?>