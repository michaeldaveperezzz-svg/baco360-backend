<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class ReportsController extends ApiBaseController
{
    private const STATUSES = ['submitted', 'under_review', 'assigned', 'in_progress', 'resolved', 'rejected', 'reopened'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    private const MAX_ATTACHMENT_SIZE = 5242880;
    private const MAX_VIDEO_SIZE = 52428800;
    private const MAX_REPORT_ATTACHMENTS = 5;
    private const MAX_STORED_ATTACHMENTS = 20;

    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
    ];

    private const VIDEO_TYPES = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function index()
    {
        $actor = $this->authenticate();
        $conditions = [];
        $values = [];
        if ($actor['role'] === 'resident') {
            $conditions[] = 'r.resident_id = ?';
            $values[] = $actor['id'];
        }
        foreach (['status', 'category_id', 'resident_id'] as $filter) {
            if (!isset($_GET[$filter]) || $_GET[$filter] === '') continue;
            if ($filter === 'status' && !in_array($_GET[$filter], self::STATUSES, true)) {
                $this->api->respond_error('Invalid report status filter.', 422);
            }
            if ($filter === 'resident_id' && $actor['role'] === 'resident') continue;
            $conditions[] = 'r.' . $filter . ' = ?';
            $values[] = $_GET[$filter];
        }
        $sql = 'SELECT r.* FROM reports r';
        if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
        $sql .= ' ORDER BY r.submitted_at DESC, r.id DESC';
        $rows = $this->db->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
        $reports = [];
        foreach ($rows as $row) $reports[] = $this->reportDetails($row);
        $this->respondSuccess(['reports' => $reports]);
    }

    public function show($id)
    {
        $actor = $this->authenticate();
        $row = $this->findReport($id);
        if (!$row) $this->api->respond_error('Report not found.', 404);
        $this->assertCanView($row, $actor);
        $this->respondSuccess($this->reportDetails($row));
    }

    public function store()
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        $input = $this->api->body();
        $report = $this->validateReportFields($input, true);
        $category = $this->db->raw(
            'SELECT id, name FROM issue_categories WHERE id = ? AND is_active = 1 LIMIT 1',
            [$report['category_id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$category) $this->api->respond_error('Choose an active report category.', 422);

        $reference = 'CC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $this->db->raw(
            'INSERT INTO reports
             (reference_number, resident_id, category_id, title, description, status, priority, latitude, longitude, location_address, submitted_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
            [
                $reference, $actor['id'], $report['category_id'], $report['title'], $report['description'],
                'submitted', $report['priority'], $report['latitude'], $report['longitude'], $report['location_address'],
            ]
        );
        $id = $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->db->raw(
            'INSERT INTO report_status_histories (report_id, changed_by, old_status, new_status, notes, changed_at)
             VALUES (?, ?, NULL, ?, ?, NOW())',
            [$id, $actor['id'], 'submitted', 'Report submitted.']
        );
        $this->notifyRoles(['admin', 'staff'], $id, 'concern_submitted', $report['priority'], $actor['id']);
        $this->audit('created', 'reports', $id, 'Submitted report ' . $reference);
        $row = $this->findReport($id);
        $this->respondSuccess([
            'message' => 'Report submitted successfully.',
            'reference_number' => $reference,
            'report' => $this->reportDetails($row),
        ], 'Report submitted.', 201);
    }

    public function update($id)
    {
        $this->api->require_method('PATCH');
        $actor = $this->authenticate();
        $row = $this->findReport($id);
        if (!$row) $this->api->respond_error('Report not found.', 404);
        $this->assertCanView($row, $actor);
        $input = $this->api->body();
        $fields = [];
        $values = [];
        $isOwner = (int) $row['resident_id'] === (int) $actor['id'];

        if ($isOwner && $actor['role'] === 'resident') {
            if ($row['status'] !== 'submitted') {
                $this->api->respond_error('A report can only be edited while it is still submitted.', 409);
            }
            $editable = $this->validateReportFields($input, false);
            foreach ($editable as $field => $value) {
                $fields[] = $field . ' = ?';
                $values[] = $value;
            }
            if (isset($input['status']) || isset($input['priority']) || isset($input['rejection_reason'])) {
                $this->api->respond_error('Residents can only edit report details.', 403);
            }
        } elseif (in_array($actor['role'], ['staff', 'admin'], true)) {
            $editable = $this->validateReportFields($input, false);
            foreach ($editable as $field => $value) {
                if ($field === 'category_id') {
                    $category = $this->db->raw(
                        'SELECT id FROM issue_categories WHERE id = ? LIMIT 1',
                        [$value]
                    )->fetch(PDO::FETCH_ASSOC);
                    if (!$category) $this->api->respond_error('Choose a valid report category.', 422);
                }
                $fields[] = $field . ' = ?';
                $values[] = $value;
            }
            if (array_key_exists('status', $input)) {
                if (!in_array($input['status'], self::STATUSES, true)) $this->api->respond_error('Invalid report status.', 422);
                $fields[] = 'status = ?';
                $values[] = $input['status'];
                if ($input['status'] === 'resolved') {
                    $fields[] = 'resolved_at = COALESCE(resolved_at, NOW())';
                } elseif ($row['status'] === 'resolved') {
                    $fields[] = 'resolved_at = NULL';
                }
            }
            if (array_key_exists('priority', $input)) {
                if (!in_array($input['priority'], self::PRIORITIES, true)) $this->api->respond_error('Invalid report priority.', 422);
                $fields[] = 'priority = ?';
                $values[] = $input['priority'];
            }
            foreach (['rejection_reason', 'resolution_description'] as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = $field . ' = ?';
                    $values[] = trim((string) $input[$field]) ?: null;
                }
            }
        } else {
            $this->api->respond_error('You are not allowed to update this report.', 403);
        }

        if (!$fields) $this->api->respond_error('No report changes were provided.', 422);
        $values[] = $id;
        $this->db->raw('UPDATE reports SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?', $values);

        if (isset($input['status']) && $input['status'] !== $row['status']) {
            $this->db->raw(
                'INSERT INTO report_status_histories (report_id, changed_by, old_status, new_status, notes, changed_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$id, $actor['id'], $row['status'], $input['status'], trim($input['notes'] ?? '')]
            );
        }
        $notificationPriority = $input['priority'] ?? $row['priority'];
        if ($actor['role'] === 'resident') {
            $this->notifyRoles(['admin', 'staff'], $id, 'concern_updated', $notificationPriority, $actor['id']);
        } else {
            $this->notifyUser($row['resident_id'], $id, 'report_updated', $notificationPriority);
        }
        $this->audit('updated', 'reports', $id, 'Updated report ' . $row['reference_number']);
        $this->respondSuccess($this->reportDetails($this->findReport($id)), 'Report updated.');
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $actor = $this->authenticate();
        $row = $this->findReport($id);
        if (!$row) $this->api->respond_error('Report not found.', 404);
        $isOwner = (int) $row['resident_id'] === (int) $actor['id'];
        if ($actor['role'] !== 'admin' && (!$isOwner || $row['status'] !== 'submitted')) {
            $this->api->respond_error('Only administrators or the report owner of a submitted report may delete it.', 403);
        }
        $this->db->raw('DELETE FROM reports WHERE id = ?', [$id]);
        $this->audit('deleted', 'reports', $id, 'Deleted report ' . $row['reference_number']);
        $this->respondSuccess(null, 'Report deleted.');
    }

    public function addComment($id)
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        $report = $this->findReport($id);
        if (!$report) $this->api->respond_error('Report not found.', 404);
        $this->assertCanView($report, $actor);
        $input = $_POST ?: $this->api->body();
        $comment = trim($input['comment'] ?? '');
        if ($comment === '' || strlen($comment) > 5000) $this->api->respond_error('Enter a comment of up to 5,000 characters.', 422);
        $isInternalInput = $input['is_internal'] ?? false;
        $internal = $actor['role'] !== 'resident' && filter_var($isInternalInput, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $photo = $_FILES['photo'] ?? null;
        $validatedPhoto = null;
        if ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (!in_array($actor['role'], ['staff', 'admin'], true) || $internal) {
                $this->api->respond_error('Update photos must be posted as a public staff update.', 403);
            }
            $temporaryPath = $photo['tmp_name'] ?? '';
            if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($photo['size'] ?? 0) < 1 || (int) $photo['size'] > self::MAX_ATTACHMENT_SIZE || !is_uploaded_file($temporaryPath)) {
                $this->api->respond_error('Update photos must be no larger than 5 MB.', 422);
            }
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            if (!isset(self::IMAGE_TYPES[$mimeType]) || @getimagesize($temporaryPath) === false) {
                $this->api->respond_error('Only valid JPG, PNG, or GIF update photos are allowed.', 422);
            }
            $validatedPhoto = [
                'temporary_path' => $temporaryPath,
                'mime_type' => $mimeType,
                'file_size' => (int) $photo['size'],
                'original_name' => substr(basename((string) $photo['name']), 0, 255),
            ];
            $existingCount = (int) $this->db->raw('SELECT COUNT(*) FROM report_attachments WHERE report_id = ?', [$id])->fetchColumn();
            if ($existingCount >= self::MAX_STORED_ATTACHMENTS) {
                $this->api->respond_error('A report can have up to 20 attachments.', 422);
            }
        }
        $this->db->raw(
            'INSERT INTO report_comments (report_id, user_id, comment, is_internal, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$id, $actor['id'], $comment, $internal]
        );
        $commentId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        if ($validatedPhoto) {
            $storageDirectory = ROOT_DIR . 'runtime/uploads/reports/';
            if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
                $this->api->respond_error('Photo storage is unavailable. Please contact support.', 500);
            }
            $attachmentType = $report['status'] === 'resolved' ? 'resolution_evidence' : 'progress_photo';
            $fileName = bin2hex(random_bytes(16)) . '.' . self::IMAGE_TYPES[$validatedPhoto['mime_type']];
            if (!move_uploaded_file($validatedPhoto['temporary_path'], $storageDirectory . $fileName)) {
                $this->api->respond_error('The update photo could not be saved. Please try again.', 500);
            }
            $this->db->raw(
                'INSERT INTO report_attachments (report_id, file_path, original_name, mime_type, file_size, attachment_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$id, $fileName, $validatedPhoto['original_name'], $validatedPhoto['mime_type'], $validatedPhoto['file_size'], $attachmentType]
            );
            $attachmentId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            $this->db->raw(
                'INSERT INTO report_comment_attachments (comment_id, attachment_id, created_at) VALUES (?, ?, NOW())',
                [$commentId, $attachmentId]
            );
        }
        if ($actor['role'] === 'resident') {
            $this->notifyRoles(['admin', 'staff'], $id, 'resident_message', $report['priority'], $actor['id']);
        } elseif (!$internal) {
            $this->notifyUser($report['resident_id'], $id, 'report_message', $report['priority']);
        }
        $this->audit('commented', 'reports', $id, 'Added a report update.');
        $this->respondSuccess($this->reportDetails($report), 'Comment added.', 201);
    }

    public function uploadAttachments($id)
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        $report = $this->findReport($id);
        if (!$report) $this->api->respond_error('Report not found.', 404);
        $this->assertCanView($report, $actor);

        $uploads = $_FILES['attachments'] ?? null;
        if (!$uploads || !isset($uploads['name']) || !is_array($uploads['name'])) {
            $this->api->respond_error('Choose at least one valid image file.', 422);
        }

        $fileCount = count($uploads['name']);
        if ($fileCount < 1 || $fileCount > self::MAX_REPORT_ATTACHMENTS) {
            $this->api->respond_error('Upload between 1 and 5 files at a time.', 422);
        }

        $existingCount = (int) $this->db->raw(
            'SELECT COUNT(*) FROM report_attachments WHERE report_id = ?',
            [$id]
        )->fetchColumn();
        if ($existingCount + $fileCount > self::MAX_STORED_ATTACHMENTS) {
            $this->api->respond_error('A report can have up to 20 attachments.', 422);
        }

        $storageDirectory = ROOT_DIR . 'runtime/uploads/reports/';
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
            $this->api->respond_error('Attachment storage is unavailable. Please contact support.', 500);
        }
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $savedAttachments = [];
        $validatedUploads = [];

        for ($index = 0; $index < $fileCount; $index++) {
            $error = $uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                $this->api->respond_error('One of the selected images could not be uploaded.', 422);
            }

            $temporaryPath = $uploads['tmp_name'][$index] ?? '';
            $fileSize = (int) ($uploads['size'][$index] ?? 0);
            if ($fileSize < 1 || !is_uploaded_file($temporaryPath)) {
                $this->api->respond_error('One of the selected files is empty or invalid.', 422);
            }

            $mimeType = $fileInfo->file($temporaryPath);
            if (isset(self::IMAGE_TYPES[$mimeType])) {
                if ($fileSize > self::MAX_ATTACHMENT_SIZE || @getimagesize($temporaryPath) === false) {
                    $this->api->respond_error('Images must be valid JPG, PNG, or GIF files no larger than 5 MB.', 422);
                }
                $attachmentType = 'report_photo';
            } elseif (isset(self::VIDEO_TYPES[$mimeType])) {
                if ($fileSize > self::MAX_VIDEO_SIZE) {
                    $this->api->respond_error('Videos must be no larger than 50 MB.', 422);
                }
                $attachmentType = 'report_video';
            } else {
                $this->api->respond_error('Only JPG, PNG, GIF, MP4, MOV, or WebM files are allowed.', 422);
            }

            $validatedUploads[] = [
                'temporary_path' => $temporaryPath,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'original_name' => substr(basename((string) $uploads['name'][$index]), 0, 255),
                'attachment_type' => $attachmentType,
            ];
        }

        foreach ($validatedUploads as $upload) {
            $mimeType = $upload['mime_type'];
            $extensions = self::IMAGE_TYPES + self::VIDEO_TYPES;
            $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
            $relativePath = $fileName;
            $absolutePath = $storageDirectory . $fileName;
            if (!move_uploaded_file($upload['temporary_path'], $absolutePath)) {
                $this->api->respond_error('An image could not be saved. Please try again.', 500);
            }

            $this->db->raw(
                'INSERT INTO report_attachments (report_id, file_path, original_name, mime_type, file_size, attachment_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $id,
                    $relativePath,
                    $upload['original_name'],
                    $mimeType,
                    $upload['file_size'],
                    $upload['attachment_type'],
                ]
            );
            $savedAttachments[] = [
                'id' => (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn(),
                'file_path' => $relativePath,
                'original_name' => $upload['original_name'],
                'mime_type' => $mimeType,
                'file_size' => $upload['file_size'],
                'attachment_type' => $upload['attachment_type'],
            ];
        }

        $this->audit('uploaded', 'reports', $id, 'Uploaded report images.');
        $this->respondSuccess([
            'attachments' => $savedAttachments,
            'count' => count($savedAttachments),
        ], 'Attachments uploaded.');
    }

    public function downloadAttachment($id, $attachmentId)
    {
        $actor = $this->authenticate();
        $report = $this->findReport($id);
        if (!$report) $this->api->respond_error('Report not found.', 404);
        if ($actor['role'] === 'resident' && (int) $report['resident_id'] !== (int) $actor['id'] && $report['status'] !== 'resolved') {
            $this->api->respond_error('Report not found.', 404);
        }

        $attachment = $this->db->raw(
            'SELECT file_path, original_name, mime_type, file_size
             FROM report_attachments WHERE id = ? AND report_id = ? LIMIT 1',
            [$attachmentId, $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$attachment || basename($attachment['file_path']) !== $attachment['file_path']) {
            $this->api->respond_error('Image not found.', 404);
        }

        $filePath = ROOT_DIR . 'runtime/uploads/reports/' . $attachment['file_path'];
        $mimeType = $attachment['mime_type'];
        if ((!isset(self::IMAGE_TYPES[$mimeType]) && !isset(self::VIDEO_TYPES[$mimeType])) || !is_file($filePath)) {
            $this->api->respond_error('Attachment not found.', 404);
        }

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($attachment['original_name'])) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($filePath);
        exit;
    }

    public function assign($id)
    {
        $this->api->require_method('POST');
        $actor = $this->requireAdmin();
        $report = $this->findReport($id);
        if (!$report) $this->api->respond_error('Report not found.', 404);
        $input = $this->api->body();
        $staffId = filter_var($input['staff_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$staffId) $this->api->respond_error('A valid staff member is required.', 422);
        $staff = $this->db->raw(
            'SELECT id, name FROM users WHERE id = ? AND role = ? AND is_active = 1 LIMIT 1',
            [$staffId, 'staff']
        )->fetch(PDO::FETCH_ASSOC);
        if (!$staff) $this->api->respond_error('The selected staff account is not active.', 422);

        $this->db->raw(
            'UPDATE report_assignments SET unassigned_at = NOW(), updated_at = NOW()
             WHERE report_id = ? AND unassigned_at IS NULL',
            [$id]
        );
        $this->db->raw(
            'INSERT INTO report_assignments (report_id, staff_id, assigned_by, department, assigned_at, assignment_notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), ?, NOW(), NOW())',
            [$id, $staffId, $actor['id'], trim($input['department'] ?? '') ?: null, trim($input['assignment_notes'] ?? '') ?: null]
        );
        $this->notifyUser($staffId, $id, 'report_assigned', $report['priority']);
        $this->notifyUser($report['resident_id'], $id, 'report_assigned', $report['priority']);
        if ($report['status'] === 'submitted' || $report['status'] === 'under_review') {
            $this->db->raw('UPDATE reports SET status = ?, updated_at = NOW() WHERE id = ?', ['assigned', $id]);
            $this->db->raw(
                'INSERT INTO report_status_histories (report_id, changed_by, old_status, new_status, notes, changed_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$id, $actor['id'], $report['status'], 'assigned', 'Assigned to staff.']
            );
        }
        $this->audit('assigned', 'reports', $id, 'Assigned report to staff member ' . $staffId);
        $this->respondSuccess($this->reportDetails($this->findReport($id)), 'Report assigned.');
    }

    private function validateReportFields($input, $required)
    {
        $result = [];
        $fields = ['category_id', 'title', 'description', 'location_address', 'latitude', 'longitude', 'priority'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $input)) {
                if ($required && in_array($field, ['category_id', 'title', 'description', 'location_address'], true)) {
                    $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field is required.', 422);
                }
                continue;
            }
            $value = $input[$field];
            if ($field === 'category_id') {
                $value = filter_var($value, FILTER_VALIDATE_INT);
                if (!$value || $value < 1) $this->api->respond_error('Choose a valid category.', 422);
                if ($required || $this->actor['role'] === 'resident') {
                    $category = $this->db->raw(
                        'SELECT id FROM issue_categories WHERE id = ? AND is_active = 1 LIMIT 1',
                        [$value]
                    )->fetch(PDO::FETCH_ASSOC);
                    if (!$category) $this->api->respond_error('Choose an active report category.', 422);
                }
            } elseif ($field === 'title') {
                $value = trim((string) $value);
                if ($value === '' || strlen($value) > 255) $this->api->respond_error('Title is required and must be 255 characters or fewer.', 422);
            } elseif ($field === 'description') {
                $value = trim((string) $value);
                if (strlen($value) > 20000 || ($required && $value === '')) $this->api->respond_error('Enter a valid report description.', 422);
            } elseif ($field === 'location_address') {
                $value = trim((string) $value);
                if (strlen($value) > 2000 || ($required && $value === '')) $this->api->respond_error('Enter a valid report location.', 422);
            } elseif ($field === 'priority') {
                if (!in_array($value, self::PRIORITIES, true)) $this->api->respond_error('Invalid report priority.', 422);
            } elseif ($value !== null && $value !== '') {
                if (!is_numeric($value)) $this->api->respond_error('Coordinates must be numbers.', 422);
                $value = (float) $value;
                $limit = $field === 'latitude' ? 90 : 180;
                if ($value < -$limit || $value > $limit) $this->api->respond_error('Coordinates are out of range.', 422);
            } else {
                $value = null;
            }
            $result[$field] = $value;
        }
        if ($required && !isset($result['priority'])) $result['priority'] = 'medium';
        if ($required && !array_key_exists('latitude', $result)) $result['latitude'] = null;
        if ($required && !array_key_exists('longitude', $result)) $result['longitude'] = null;
        return $result;
    }

    private function findReport($id)
    {
        return $this->db->raw('SELECT * FROM reports WHERE id = ? LIMIT 1', [$id])->fetch(PDO::FETCH_ASSOC);
    }

    private function assertCanView($report, $actor)
    {
        if ($actor['role'] === 'resident' && (int) $report['resident_id'] !== (int) $actor['id']) {
            $this->api->respond_error('Report not found.', 404);
        }
    }

    private function reportDetails($report)
    {
        if (!$report) $this->api->respond_error('Report not found.', 404);
        $report['attachments'] = $this->db->raw(
            'SELECT id, file_path, original_name, mime_type, file_size, attachment_type
             FROM report_attachments WHERE report_id = ? ORDER BY id',
            [$report['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $report['assignments'] = $this->db->raw(
            'SELECT a.staff_id, u.name AS staff_name, a.department, a.assigned_at, a.assignment_notes
             FROM report_assignments a JOIN users u ON u.id = a.staff_id
             WHERE a.report_id = ? AND a.unassigned_at IS NULL ORDER BY a.id',
            [$report['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $report['assignment_history'] = [];
        if ($this->actor['role'] !== 'resident') {
            $report['assignment_history'] = $this->db->raw(
                'SELECT a.staff_id, u.name AS staff_name, a.department, a.assigned_at, a.unassigned_at, a.assignment_notes
                 FROM report_assignments a JOIN users u ON u.id = a.staff_id
                 WHERE a.report_id = ? ORDER BY a.assigned_at, a.id',
                [$report['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $report['status_history'] = $this->db->raw(
            'SELECT h.id, h.old_status, h.new_status, h.notes, h.changed_at, COALESCE(u.name, \'System\') AS changed_by
             FROM report_status_histories h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.report_id = ? ORDER BY h.changed_at, h.id',
            [$report['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $commentsSql = 'SELECT c.id, u.name AS user_name, c.comment, c.is_internal, c.created_at
                        FROM report_comments c JOIN users u ON u.id = c.user_id
                        WHERE c.report_id = ?';
        $commentValues = [$report['id']];
        if ($this->actor['role'] === 'resident') {
            $commentsSql .= ' AND c.is_internal = 0';
        }
        $report['comments'] = $this->db->raw($commentsSql . ' ORDER BY c.created_at, c.id', $commentValues)->fetchAll(PDO::FETCH_ASSOC);
        if ($report['comments']) {
            $commentIds = array_column($report['comments'], 'id');
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $commentAttachments = $this->db->raw(
                'SELECT ca.comment_id, a.id, a.file_path, a.original_name, a.mime_type, a.file_size, a.attachment_type
                 FROM report_comment_attachments ca JOIN report_attachments a ON a.id = ca.attachment_id
                 WHERE ca.comment_id IN (' . $placeholders . ') ORDER BY ca.id',
                $commentIds
            )->fetchAll(PDO::FETCH_ASSOC);
            $attachmentsByComment = [];
            foreach ($commentAttachments as $attachment) {
                $commentId = (int) $attachment['comment_id'];
                unset($attachment['comment_id']);
                $attachmentsByComment[$commentId][] = $attachment;
            }
            foreach ($report['comments'] as &$comment) {
                $comment['attachments'] = $attachmentsByComment[(int) $comment['id']] ?? [];
            }
            unset($comment);
        }

        foreach (['id', 'resident_id', 'category_id'] as $field) $report[$field] = (int) $report[$field];
        foreach (['latitude', 'longitude'] as $field) {
            $report[$field] = $report[$field] === null ? null : (float) $report[$field];
        }
        return $report;
    }
}
