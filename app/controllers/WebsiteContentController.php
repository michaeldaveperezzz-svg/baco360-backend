<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class WebsiteContentController extends ApiBaseController
{
    private const MAX_WEBSITE_IMAGE_SIZE = 5242880;
    private const WEBSITE_IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const SETTINGS_FIELDS = [
        'site_name' => ['type' => 'string', 'max' => 120, 'required' => true],
        'logo_path' => ['type' => 'string', 'max' => 500, 'nullable' => true],
        'primary_color' => ['type' => 'color', 'nullable' => true],
        'hero_title' => ['type' => 'string', 'max' => 255, 'nullable' => true],
        'hero_description' => ['type' => 'string', 'max' => 65535, 'nullable' => true],
        'hero_image_path' => ['type' => 'string', 'max' => 500, 'nullable' => true],
        'maintenance_enabled' => ['type' => 'boolean'],
        'maintenance_message' => ['type' => 'string', 'max' => 500, 'nullable' => true],
        'maintenance_starts_at' => ['type' => 'datetime', 'nullable' => true],
        'maintenance_ends_at' => ['type' => 'datetime', 'nullable' => true],
        'about_title' => ['type' => 'string', 'max' => 255, 'nullable' => true],
        'about_content' => ['type' => 'string', 'max' => 65535, 'nullable' => true],
        'contact_email' => ['type' => 'email', 'max' => 254, 'nullable' => true],
        'contact_phone' => ['type' => 'string', 'max' => 50, 'nullable' => true],
        'contact_address' => ['type' => 'string', 'max' => 65535, 'nullable' => true],
        'footer_text' => ['type' => 'string', 'max' => 65535, 'nullable' => true],
        'copyright_text' => ['type' => 'string', 'max' => 255, 'nullable' => true],
    ];

    private const COLLECTIONS = [
        'competencies' => [
            'table' => 'website_competencies',
            'entity' => 'website_competencies',
            'fields' => [
                'name' => ['type' => 'string', 'max' => 150, 'required' => true],
                'description' => ['type' => 'string', 'max' => 65535, 'nullable' => true],
                'icon_name' => ['type' => 'string', 'max' => 80, 'nullable' => true],
                'sort_order' => ['type' => 'integer'],
                'is_active' => ['type' => 'boolean'],
            ],
        ],
        'announcements' => [
            'table' => 'website_landing_announcements',
            'entity' => 'website_landing_announcements',
            'fields' => [
                'title' => ['type' => 'string', 'max' => 255, 'required' => true],
                'message' => ['type' => 'string', 'max' => 65535, 'required' => true],
                'image_path' => ['type' => 'string', 'max' => 500, 'nullable' => true],
                'starts_at' => ['type' => 'datetime', 'nullable' => true],
                'ends_at' => ['type' => 'datetime', 'nullable' => true],
                'sort_order' => ['type' => 'integer'],
                'is_active' => ['type' => 'boolean'],
            ],
        ],
        'gallery' => [
            'table' => 'website_gallery_images',
            'entity' => 'website_gallery_images',
            'fields' => [
                'image_path' => ['type' => 'string', 'max' => 500, 'required' => true],
                'alt_text' => ['type' => 'string', 'max' => 255],
                'caption' => ['type' => 'string', 'max' => 65535, 'nullable' => true],
                'sort_order' => ['type' => 'integer'],
                'is_active' => ['type' => 'boolean'],
            ],
        ],
        'faqs' => [
            'table' => 'website_faqs',
            'entity' => 'website_faqs',
            'fields' => [
                'question' => ['type' => 'string', 'max' => 500, 'required' => true],
                'answer' => ['type' => 'string', 'max' => 65535, 'required' => true],
                'sort_order' => ['type' => 'integer'],
                'is_active' => ['type' => 'boolean'],
            ],
        ],
    ];

    public function index()
    {
        $this->api->require_method('GET');
        $this->respondSuccess($this->contentPayload(false));
    }

    public function adminIndex()
    {
        $this->api->require_method('GET');
        $this->requireAdmin();
        $this->respondSuccess($this->contentPayload(true));
    }

    public function uploadImage()
    {
        $this->api->require_method('POST');
        $this->requireAdmin();
        $upload = $_FILES['image'] ?? null;
        if (!$upload || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
            $this->api->respond_error('Choose an image to upload.', 422);
        }
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $this->api->respond_error('The selected image could not be uploaded.', 422);
        }

        $temporaryPath = (string) $upload['tmp_name'];
        $fileSize = (int) $upload['size'];
        if (
            $fileSize < 1
            || $fileSize > self::MAX_WEBSITE_IMAGE_SIZE
            || !is_uploaded_file($temporaryPath)
        ) {
            $this->api->respond_error('Images must be valid files no larger than 5 MB.', 422);
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        if (
            !isset(self::WEBSITE_IMAGE_TYPES[$mimeType])
            || !$imageInfo
            || ($imageInfo['mime'] ?? null) !== $mimeType
            || (int) $imageInfo[0] < 1
            || (int) $imageInfo[1] < 1
            || (int) $imageInfo[0] * (int) $imageInfo[1] > 25000000
        ) {
            $this->api->respond_error('Only valid JPG, PNG, GIF, or WebP images are allowed.', 422);
        }

        $storageDirectory = ROOT_DIR . 'runtime/uploads/website-content/';
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
            $this->api->respond_error('Website image storage is unavailable. Please contact support.', 500);
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . self::WEBSITE_IMAGE_TYPES[$mimeType];
        $absolutePath = $storageDirectory . $fileName;
        if (!move_uploaded_file($temporaryPath, $absolutePath)) {
            $this->api->respond_error('The image could not be saved. Please try again.', 500);
        }
        chmod($absolutePath, 0640);

        $this->respondSuccess([
            'path' => '/website-content/media/' . $fileName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ], 'Image uploaded.', 201);
    }

    public function media($fileName)
    {
        $this->api->require_method('GET');
        if (
            !is_string($fileName)
            || basename($fileName) !== $fileName
            || !preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $fileName)
        ) {
            $this->api->respond_error('Website image not found.', 404);
        }

        $filePath = ROOT_DIR . 'runtime/uploads/website-content/' . $fileName;
        if (!is_file($filePath)) {
            $this->api->respond_error('Website image not found.', 404);
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        $imageInfo = @getimagesize($filePath);
        if (
            !isset(self::WEBSITE_IMAGE_TYPES[$mimeType])
            || !$imageInfo
            || ($imageInfo['mime'] ?? null) !== $mimeType
            || pathinfo($fileName, PATHINFO_EXTENSION) !== self::WEBSITE_IMAGE_TYPES[$mimeType]
        ) {
            $this->api->respond_error('Website image not found.', 404);
        }

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($filePath);
        exit;
    }

    public function updateSettings()
    {
        $this->api->require_method('PUT');
        $actor = $this->requireAdmin();
        $input = $this->requestBody();
        $fields = $this->validatedFields($input, self::SETTINGS_FIELDS, false);
        if (!$fields) {
            $this->api->respond_error('No website settings changes were provided.', 422);
        }
        $currentSettings = $this->getSettings();
        $maintenanceStartsAt = array_key_exists('maintenance_starts_at', $fields)
            ? $fields['maintenance_starts_at']
            : $currentSettings['maintenance_starts_at'];
        $maintenanceEndsAt = array_key_exists('maintenance_ends_at', $fields)
            ? $fields['maintenance_ends_at']
            : $currentSettings['maintenance_ends_at'];
        if (
            $maintenanceStartsAt !== null
            && $maintenanceEndsAt !== null
            && $maintenanceStartsAt > $maintenanceEndsAt
        ) {
            $this->api->respond_error('The maintenance end time must be after its start time.', 422);
        }

        $columns = array_keys($fields);
        $insertColumns = array_map(static function ($column) {
            return '`' . $column . '`';
        }, $columns);
        $insertValues = array_values($fields);
        $insertColumns[] = '`id`';
        $insertColumns[] = '`updated_by`';
        $insertColumns[] = '`updated_at`';
        $insertValues[] = $actor['id'];

        $updates = [];
        foreach ($columns as $column) {
            $updates[] = '`' . $column . '` = VALUES(`' . $column . '`)';
        }
        $updates[] = '`updated_by` = VALUES(`updated_by`)';
        $updates[] = '`updated_at` = NOW()';
        $placeholders = implode(', ', array_fill(0, count($fields), '?')) . ', 1, ?, NOW()';

        $this->db->raw(
            'INSERT INTO website_content_settings (' . implode(', ', $insertColumns) . ')
             VALUES (' . $placeholders . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            $insertValues
        );

        $auditSaved = $this->recordAudit('updated', 'website_content_settings', 1, 'Updated public website content settings.');
        $message = $auditSaved
            ? 'Website content settings saved.'
            : 'Website content settings were saved, but the audit record could not be saved.';
        $this->respondSuccess(['settings' => $this->getSettings()], $message);
    }

    public function collectionCreate($collection)
    {
        $this->api->require_method('POST');
        $this->requireAdmin();
        $config = $this->collectionConfig($collection);
        $input = $this->requestBody();
        $fields = $this->validatedFields($input, $config['fields'], true);

        $columns = array_map(static function ($column) {
            return '`' . $column . '`';
        }, array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $this->db->raw(
            'INSERT INTO `' . $config['table'] . '` (' . implode(', ', $columns) . ')
             VALUES (' . $placeholders . ')',
            array_values($fields)
        );
        $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $auditSaved = $this->recordAudit('created', $config['entity'], $id, 'Created website content item in ' . $collection . '.');
        $message = $auditSaved
            ? 'Website content item created.'
            : 'Website content item was created, but the audit record could not be saved.';
        $this->respondSuccess(['item' => $this->findItem($config, $id)], $message, 201);
    }

    public function collectionUpdate($collection, $id)
    {
        $this->api->require_method('PUT');
        $this->requireAdmin();
        $config = $this->collectionConfig($collection);
        $id = $this->validateId($id);
        if (!$this->findItem($config, $id)) {
            $this->api->respond_error('Website content item not found.', 404);
        }

        $input = $this->requestBody();
        $fields = $this->validatedFields($input, $config['fields'], false);
        if (!$fields) {
            $this->api->respond_error('No website content changes were provided.', 422);
        }
        $assignments = [];
        $values = [];
        foreach ($fields as $field => $value) {
            $assignments[] = '`' . $field . '` = ?';
            $values[] = $value;
        }
        $assignments[] = '`updated_at` = NOW()';
        $values[] = $id;
        $this->db->raw(
            'UPDATE `' . $config['table'] . '` SET ' . implode(', ', $assignments) . ' WHERE id = ?',
            $values
        );

        $auditSaved = $this->recordAudit('updated', $config['entity'], $id, 'Updated website content item in ' . $collection . '.');
        $message = $auditSaved
            ? 'Website content item updated.'
            : 'Website content item was updated, but the audit record could not be saved.';
        $this->respondSuccess(['item' => $this->findItem($config, $id)], $message);
    }

    public function collectionDelete($collection, $id)
    {
        $this->api->require_method('DELETE');
        $this->requireAdmin();
        $config = $this->collectionConfig($collection);
        $id = $this->validateId($id);
        if (!$this->findItem($config, $id)) {
            $this->api->respond_error('Website content item not found.', 404);
        }

        $this->db->raw('DELETE FROM `' . $config['table'] . '` WHERE id = ?', [$id]);
        $auditSaved = $this->recordAudit('deleted', $config['entity'], $id, 'Deleted website content item in ' . $collection . '.');
        $message = $auditSaved
            ? 'Website content item deleted.'
            : 'Website content item was deleted, but the audit record could not be saved.';
        $this->respondSuccess(null, $message);
    }

    private function contentPayload($includeInactive)
    {
        $settings = $this->getSettings();
        if (!$includeInactive && $settings['maintenance_enabled']) {
            $timezone = config_item('date_default_timezone') ?: 'Asia/Manila';
            $now = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d H:i:s');
            $settings['maintenance_enabled'] = (bool) $this->db->raw(
                'SELECT maintenance_enabled = 1
                    AND (maintenance_starts_at IS NULL OR maintenance_starts_at <= ?)
                    AND (maintenance_ends_at IS NULL OR maintenance_ends_at >= ?)
                 FROM website_content_settings WHERE id = 1 LIMIT 1',
                [$now, $now]
            )->fetchColumn();
        }
        $content = [
            'settings' => $settings,
        ];
        foreach (array_keys(self::COLLECTIONS) as $collection) {
            $content[$collection] = $this->getCollectionItems($collection, $includeInactive);
        }
        return $content;
    }

    private function getSettings()
    {
        $settings = $this->db->raw(
            'SELECT site_name, logo_path, primary_color, hero_title, hero_description, hero_image_path,
                    maintenance_enabled, maintenance_message, maintenance_starts_at, maintenance_ends_at,
                   about_title, about_content, contact_email, contact_phone, contact_address,
                    footer_text, copyright_text, updated_at
             FROM website_content_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            return [
                'site_name' => 'Baco360',
                'logo_path' => null,
                'primary_color' => null,
                'hero_title' => null,
                'hero_description' => null,
                'hero_image_path' => null,
                'maintenance_enabled' => false,
                'maintenance_message' => null,
                'maintenance_starts_at' => null,
                'maintenance_ends_at' => null,
                'about_title' => null,
                'about_content' => null,
                'contact_email' => null,
                'contact_phone' => null,
                'contact_address' => null,
                'footer_text' => null,
                'copyright_text' => null,
                'updated_at' => null,
            ];
        }
        $settings['maintenance_enabled'] = (bool) $settings['maintenance_enabled'];
        return $settings;
    }

    private function getCollectionItems($collection, $includeInactive)
    {
        $config = $this->collectionConfig($collection);
        $where = [];
        $values = [];
        if (!$includeInactive) {
            $where[] = 'is_active = 1';
        }
        if ($collection === 'announcements' && !$includeInactive) {
            $where[] = '(starts_at IS NULL OR starts_at <= NOW())';
            $where[] = '(ends_at IS NULL OR ends_at >= NOW())';
        }

        $sql = 'SELECT * FROM `' . $config['table'] . '`';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $items = $this->db->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            $item['sort_order'] = (int) $item['sort_order'];
            $item['is_active'] = (bool) $item['is_active'];
        }
        unset($item);
        return $items;
    }

    private function findItem($config, $id)
    {
        $item = $this->db->raw(
            'SELECT * FROM `' . $config['table'] . '` WHERE id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            $item['id'] = (int) $item['id'];
            if (array_key_exists('sort_order', $item)) $item['sort_order'] = (int) $item['sort_order'];
            if (array_key_exists('is_active', $item)) $item['is_active'] = (bool) $item['is_active'];
        }
        return $item;
    }

    private function collectionConfig($collection)
    {
        if (!isset(self::COLLECTIONS[$collection])) {
            $this->api->respond_error('Unknown website content collection.', 404);
        }
        return self::COLLECTIONS[$collection];
    }

    private function requestBody()
    {
        $input = $this->api->body();
        if (!is_array($input)) {
            $this->api->respond_error('A JSON object is required.', 422);
        }
        return $input;
    }

    private function validatedFields($input, $rules, $requireRequired)
    {
        $fields = [];
        foreach ($rules as $field => $rule) {
            if (!array_key_exists($field, $input)) {
                if ($requireRequired && !empty($rule['required'])) {
                    $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field is required.', 422);
                }
                continue;
            }

            $value = $input[$field];
            switch ($rule['type']) {
                case 'string':
                case 'email':
                case 'color':
                    if ($value === null && !empty($rule['nullable'])) {
                        $fields[$field] = null;
                        continue 2;
                    }
                    if (!is_string($value)) {
                        $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field must be text.', 422);
                    }
                    $value = trim($value);
                    if (!empty($rule['required']) && $value === '') {
                        $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field is required.', 422);
                    }
                    if (strlen($value) > $rule['max']) {
                        $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field is too long.', 422);
                    }
                    if ($rule['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->api->respond_error('Enter a valid contact email address.', 422);
                    }
                    if ($rule['type'] === 'color' && $value !== null && !preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                        $this->api->respond_error('The primary color must be a six-digit hexadecimal color.', 422);
                    }
                    $fields[$field] = $value === '' && !empty($rule['nullable']) ? null : $value;
                    break;

                case 'boolean':
                    $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($boolean === null) {
                        $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field must be true or false.', 422);
                    }
                    $fields[$field] = $boolean ? 1 : 0;
                    break;

                case 'integer':
                    $integer = filter_var($value, FILTER_VALIDATE_INT);
                    if ($integer === false || $integer < 0) {
                        $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field must be a non-negative integer.', 422);
                    }
                    $fields[$field] = $integer;
                    break;

                case 'datetime':
                    $fields[$field] = $this->validateDateTime($field, $value);
                    break;
            }
        }
        return $fields;
    }

    private function validateDateTime($field, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field must be a valid date and time.', 422);
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field must be a valid date and time.', 422);
    }

    private function validateId($id)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $this->api->respond_error('Invalid website content item ID.', 422);
        }
        return $id;
    }

    private function recordAudit($action, $entityType, $entityId, $description)
    {
        try {
            $this->audit($action, $entityType, $entityId, $description);
            return true;
        } catch (Throwable $error) {
            error_log('Website content changed but its audit record could not be saved: ' . $error->getMessage());
            return false;
        }
    }
}
