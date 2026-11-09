<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';
require_once APP_DIR . 'libraries/NotificationEmailService.php';

class ProfilesController extends ApiBaseController
{
    private const PROFILE_FIELDS = [
        'birth_date' => 10,
        'gender' => 100,
        'street' => 255,
        'barangay' => 150,
        'municipality' => 150,
        'province' => 150,
        'postal_code' => 20,
        'landmark' => 255,
    ];

    private const CONTACT_METHODS = ['Email', 'SMS', 'Both'];
    private const PHOTO_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const MAX_PHOTO_SIZE = 5242880;

    public function residentProfile()
    {
        $this->api->require_method('GET');
        $actor = $this->requireResident();
        $this->ensureResidentProfile($actor['id']);
        $this->respondSuccess($this->residentProfileWithCompletion($actor['id']));
    }

    public function updateResidentProfile()
    {
        $this->api->require_method('PUT');
        $actor = $this->requireResident();
        $input = $this->api->body();
        $this->ensureResidentProfile($actor['id']);

        $current = $this->db->raw(
            'SELECT * FROM resident_profiles WHERE user_id = ? LIMIT 1',
            [$actor['id']]
        )->fetch(PDO::FETCH_ASSOC);
        $updates = [];
        $values = [];
        $addressChanged = false;

        foreach (self::PROFILE_FIELDS as $field => $maxLength) {
            if (!array_key_exists($field, $input)) continue;
            if (!is_string($input[$field]) && $input[$field] !== null) {
                $this->api->respond_error('Invalid ' . str_replace('_', ' ', $field) . '.', 422);
            }
            $value = trim((string) ($input[$field] ?? ''));
            if ($field === 'birth_date') {
                if ($value !== '' && !$this->isValidBirthDate($value)) {
                    $this->api->respond_error('Enter a valid birth date that is not in the future.', 422);
                }
                $value = $value ?: null;
            } else {
                if (strlen($value) > $maxLength) {
                    $this->api->respond_error('Invalid ' . str_replace('_', ' ', $field) . '.', 422);
                }
                $value = $value ?: null;
            }
            if (in_array($field, ['street', 'barangay', 'municipality', 'province', 'postal_code', 'landmark'], true)
                && (string) ($current[$field] ?? '') !== (string) ($value ?? '')) {
                $addressChanged = true;
            }
            $updates[] = $field . ' = ?';
            $values[] = $value;
        }

        if (array_key_exists('preferred_contact_method', $input)) {
            $contactMethod = $input['preferred_contact_method'];
            if ($contactMethod === '') $contactMethod = null;
            if ($contactMethod !== null && !in_array($contactMethod, self::CONTACT_METHODS, true)) {
                $this->api->respond_error('Choose Email, SMS, or Both as your preferred contact method.', 422);
            }
            $updates[] = 'preferred_contact_method = ?';
            $values[] = $contactMethod;
        }

        if (!$updates) $this->api->respond_error('No resident profile changes were provided.', 422);

        if ($addressChanged) {
            $updates[] = 'address_verification_status = ?';
            $values[] = 'Pending';
            $updates[] = 'verified_by = NULL';
            $updates[] = 'verified_at = NULL';
        }

        $updates[] = 'updated_at = NOW()';
        $values[] = $actor['id'];
        $this->db->raw(
            'UPDATE resident_profiles SET ' . implode(', ', $updates) . ' WHERE user_id = ?',
            $values
        );
        $this->updateProfileCompletion($actor['id']);
        $this->respondSuccess($this->residentProfileWithCompletion($actor['id']), 'Resident profile updated.');
    }

    public function verificationQueue()
    {
        $this->api->require_method('GET');
        $this->requireVerifier();
        $status = $_GET['status'] ?? '';
        if ($status !== '' && !in_array($status, ['Pending', 'Verified', 'Rejected'], true)) {
            $this->api->respond_error('Invalid address verification status.', 422);
        }

        $sql = 'SELECT rp.id, rp.user_id, u.name, u.email, u.phone,
                       rp.street, rp.barangay, rp.municipality, rp.province, rp.postal_code, rp.landmark,
                       rp.address_verification_status, rp.verified_by,
                       verifier.name AS verified_by_name, rp.verified_at, rp.created_at, rp.updated_at
                FROM resident_profiles rp
                JOIN users u ON u.id = rp.user_id AND u.role = \'resident\'
                LEFT JOIN users verifier ON verifier.id = rp.verified_by';
        $values = [];
        if ($status !== '') {
            $sql .= ' WHERE rp.address_verification_status = ?';
            $values[] = $status;
        }
        $sql .= ' ORDER BY CASE rp.address_verification_status
                    WHEN \'Pending\' THEN 0 WHEN \'Rejected\' THEN 1 ELSE 2 END,
                    rp.updated_at DESC, rp.id DESC LIMIT 200';
        $profiles = $this->db->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
        $this->respondSuccess(['profiles' => $profiles]);
    }

    public function updateAddressVerification($id)
    {
        $this->api->require_method('PATCH');
        $actor = $this->requireVerifier();
        if (!$this->isPositiveId($id)) $this->api->respond_error('Resident profile not found.', 404);
        $input = $this->api->body();
        $status = $input['status'] ?? null;
        if (!in_array($status, ['Verified', 'Rejected'], true)) {
            $this->api->respond_error('Choose Verified or Rejected as the address status.', 422);
        }

        $profile = $this->db->raw(
            'SELECT rp.id, rp.user_id, rp.address_verification_status FROM resident_profiles rp
             JOIN users u ON u.id = rp.user_id AND u.role = \'resident\'
             WHERE rp.id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$profile) $this->api->respond_error('Resident profile not found.', 404);

        $statusChanged = $profile['address_verification_status'] !== $status;
        $this->db->raw(
            'UPDATE resident_profiles
             SET address_verification_status = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW()
             WHERE id = ?',
            [$status, $actor['id'], $id]
        );
        $this->audit(
            'updated',
            'resident_profiles',
            $id,
            'Address verification ' . strtolower($status) . ' for resident ' . $profile['user_id']
        );

        if ($statusChanged) {
            $eventType = $status === 'Verified' ? 'address_verified' : 'address_rejected';
            $this->db->raw(
                'INSERT INTO user_notifications (recipient_id, report_id, actor_id, event_type, priority, created_at)
                 VALUES (?, NULL, ?, ?, ?, NOW())',
                [$profile['user_id'], $actor['id'], $eventType, 'medium']
            );
            NotificationEmailService::queueAddressVerificationToResident(
                $this->db,
                (int) $profile['id'],
                (int) $profile['user_id'],
                $status,
                (string) $actor['name'],
                config_item('frontend_url')
            );
        }

        $updated = $this->db->raw(
            'SELECT rp.id, rp.user_id, u.name, u.email, u.phone, rp.street, rp.barangay,
                    rp.municipality, rp.province, rp.postal_code, rp.landmark,
                    rp.address_verification_status, rp.verified_by, verifier.name AS verified_by_name,
                    rp.verified_at, rp.updated_at
             FROM resident_profiles rp
             JOIN users u ON u.id = rp.user_id
             LEFT JOIN users verifier ON verifier.id = rp.verified_by
             WHERE rp.id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
        $this->respondSuccess($updated, 'Address verification updated.');
    }

    public function uploadProfilePhoto()
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        $photo = $_FILES['photo'] ?? null;
        if (!$photo || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->api->respond_error('Choose a profile photo to upload.', 422);
        }
        $temporaryPath = $photo['tmp_name'] ?? '';
        if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int) ($photo['size'] ?? 0) < 1
            || (int) $photo['size'] > self::MAX_PHOTO_SIZE
            || !is_uploaded_file($temporaryPath)) {
            $this->api->respond_error('Profile photos must be no larger than 5 MB.', 422);
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        if (!isset(self::PHOTO_TYPES[$mimeType])
            || !$imageInfo
            || ($imageInfo['mime'] ?? null) !== $mimeType
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] * $imageInfo[1] > 16000000) {
            $this->api->respond_error('Upload a valid JPG, PNG, or WebP image up to 16 megapixels.', 422);
        }

        $storageDirectory = ROOT_DIR . 'runtime/uploads/profile-photos/';
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
            $this->api->respond_error('Profile photo storage is unavailable. Please contact support.', 500);
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . self::PHOTO_TYPES[$mimeType];
        $absolutePath = $storageDirectory . $fileName;
        if (!move_uploaded_file($temporaryPath, $absolutePath)) {
            $this->api->respond_error('The profile photo could not be saved. Please try again.', 500);
        }
        chmod($absolutePath, 0640);

        try {
            $this->db->raw(
                'UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?',
                ['profile-photos/' . $fileName, $actor['id']]
            );
        } catch (Throwable $error) {
            if (is_file($absolutePath)) unlink($absolutePath);
            throw $error;
        }
        $this->deleteStoredPhoto($actor['profile_photo'] ?? null);

        $user = $this->getUser($actor['id']);
        $user['notification_settings'] = $this->getNotificationSettings($actor['id']);
        $user = $this->withProfilePhotoUrl($user);
        $this->respondSuccess($user, 'Profile photo uploaded.');
    }

    public function removeProfilePhoto()
    {
        $this->api->require_method('DELETE');
        $actor = $this->authenticate();
        $this->db->raw(
            'UPDATE users SET profile_photo = NULL, updated_at = NOW() WHERE id = ?',
            [$actor['id']]
        );
        $this->deleteStoredPhoto($actor['profile_photo'] ?? null);

        $user = $this->getUser($actor['id']);
        $user['notification_settings'] = $this->getNotificationSettings($actor['id']);
        $user = $this->withProfilePhotoUrl($user);
        $this->respondSuccess($user, 'Profile photo removed.');
    }

    public function showProfilePhoto($id)
    {
        $this->api->require_method('GET');
        $this->authenticate();
        if (!$this->isPositiveId($id)) $this->api->respond_error('Profile photo not found.', 404);
        $photoPath = $this->db->raw(
            'SELECT profile_photo FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
            [$id]
        )->fetchColumn();
        if (!is_string($photoPath) || !$this->isStoredPhotoPath($photoPath)) {
            $this->api->respond_error('Profile photo not found.', 404);
        }

        $filePath = ROOT_DIR . 'runtime/uploads/' . $photoPath;
        if (!is_file($filePath)) $this->api->respond_error('Profile photo not found.', 404);
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        $imageInfo = @getimagesize($filePath);
        if (!isset(self::PHOTO_TYPES[$mimeType])
            || !$imageInfo
            || ($imageInfo['mime'] ?? null) !== $mimeType
            || pathinfo($filePath, PATHINFO_EXTENSION) !== self::PHOTO_TYPES[$mimeType]) {
            $this->api->respond_error('Profile photo not found.', 404);
        }

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="profile-photo"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Vary: Authorization');
        readfile($filePath);
        exit;
    }

    private function requireResident()
    {
        $actor = $this->authenticate();
        if ($actor['role'] !== 'resident') {
            $this->api->respond_error('Resident access is required.', 403);
        }
        return $actor;
    }

    private function requireVerifier()
    {
        $actor = $this->authenticate();
        if (!in_array($actor['role'], ['staff', 'admin'], true)) {
            $this->api->respond_error('Staff or administrator access is required.', 403);
        }
        return $actor;
    }

    private function ensureResidentProfile($userId)
    {
        $this->db->raw(
            'INSERT INTO resident_profiles (user_id) VALUES (?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
            [$userId]
        );
    }

    private function residentProfileWithCompletion($userId)
    {
        $profile = $this->db->raw(
            'SELECT rp.*, u.name, u.email, u.phone, u.profile_photo
             FROM resident_profiles rp JOIN users u ON u.id = rp.user_id
             WHERE rp.user_id = ? AND u.role = \'resident\' LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$profile) $this->api->respond_error('Resident profile not found.', 404);

        $profile['completion_percentage'] = $this->completionPercentage($profile);
        $profile['is_profile_complete'] = (bool) $profile['is_profile_complete'];
        $profile['profile_photo_url'] = !empty($profile['profile_photo'])
            ? '/user/profile-photo/' . rawurlencode((string) $profile['user_id'])
            : null;
        return $profile;
    }

    private function updateProfileCompletion($userId)
    {
        $profile = $this->db->raw(
            'SELECT birth_date, street, barangay, postal_code, preferred_contact_method
             FROM resident_profiles WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$profile) $this->api->respond_error('Resident profile not found.', 404);
        $complete = $this->completionPercentage($profile) === 100 ? 1 : 0;
        $this->db->raw(
            'UPDATE resident_profiles SET is_profile_complete = ?, updated_at = NOW() WHERE user_id = ?',
            [$complete, $userId]
        );
    }

    private function completionPercentage($profile)
    {
        $requiredFields = ['birth_date', 'street', 'barangay', 'postal_code', 'preferred_contact_method'];
        $completeFields = 0;
        foreach ($requiredFields as $field) {
            if (isset($profile[$field]) && trim((string) $profile[$field]) !== '') $completeFields++;
        }
        return (int) round($completeFields * 100 / count($requiredFields));
    }

    private function isValidBirthDate($value)
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value
            && $value <= date('Y-m-d');
    }

    private function isPositiveId($id)
    {
        return is_scalar($id) && preg_match('/^[1-9][0-9]*$/', (string) $id) === 1;
    }

    private function isStoredPhotoPath($path)
    {
        return preg_match('/\Aprofile-photos\/[a-f0-9]{32}\.(jpg|png|webp)\z/', $path) === 1;
    }

    private function deleteStoredPhoto($path)
    {
        if (!is_string($path) || !$this->isStoredPhotoPath($path)) return;
        $filePath = ROOT_DIR . 'runtime/uploads/' . $path;
        if (is_file($filePath) && !unlink($filePath)) {
            error_log('Unable to remove replaced profile photo: ' . $filePath);
        }
    }
}
