<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class UsersController extends ApiBaseController
{
    public function index()
    {
        $this->requireAdmin();
        $sql = 'SELECT id, name, email, phone, role, is_active, email_verified_at, profile_photo, created_at FROM users';
        $conditions = [];
        $values = [];
        if (!empty($_GET['role']) && in_array($_GET['role'], ['resident', 'staff', 'admin'], true)) {
            $conditions[] = 'role = ?';
            $values[] = $_GET['role'];
        }
        if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
            $conditions[] = 'is_active = ?';
            $values[] = filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
        $sql .= ' ORDER BY created_at DESC, id DESC';
        $users = $this->db->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) {
            $user['notification_settings'] = $this->getNotificationSettings($user['id']);
            $user = $this->withProfilePhotoUrl($user);
        }
        unset($user);
        $this->respondSuccess(['users' => $users]);
    }

    public function show($id)
    {
        $this->requireAdmin();
        $user = $this->getUser($id);
        if (!$user) $this->api->respond_error('User not found.', 404);
        $user['notification_settings'] = $this->getNotificationSettings($id);
        $this->respondSuccess($this->withProfilePhotoUrl($user));
    }

    public function store()
    {
        $this->api->require_method('POST');
        $actor = $this->requireAdmin();
        $input = $this->api->body();
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $phone = trim($input['phone'] ?? '');
        $role = $input['role'] ?? 'resident';
        $password = $input['password'] ?? '';

        if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $this->api->respond_error('Enter a valid name and email address.', 422);
        }
        if (!in_array($role, ['resident', 'staff', 'admin'], true)) {
            $this->api->respond_error('Choose a valid user role.', 422);
        }
        if (!is_string($password) || strlen($password) < 8) {
            $this->api->respond_error('The password must contain at least 8 characters.', 422);
        }
        if ($this->db->raw('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC)) {
            $this->api->respond_error('An account with this email already exists.', 409);
        }

        $this->db->raw(
            'INSERT INTO users (name, email, phone, password, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [$name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), $role]
        );
        $id = $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->db->raw(
            'INSERT INTO notification_settings (user_id, sms_enabled, email_enabled, report_updates_enabled, resolution_updates_enabled)
             VALUES (?, ?, ?, 1, 1)',
            [$id, !empty($input['sms_enabled']) ? 1 : 0, !empty($input['email_enabled']) ? 1 : 0]
        );
        $this->actor = $actor;
        $this->audit('created', 'users', $id, 'Created user account ' . $email);
        $user = $this->getUser($id);
        $user['notification_settings'] = $this->getNotificationSettings($id);
        $this->respondSuccess($this->withProfilePhotoUrl($user), 'User created.', 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $actor = $this->requireAdmin();
        $user = $this->getUser($id);
        if (!$user) $this->api->respond_error('User not found.', 404);
        $input = $this->api->body();
        $fields = [];
        $values = [];

        foreach (['name' => 150, 'email' => 150, 'phone' => 30] as $field => $maxLength) {
            if (!array_key_exists($field, $input)) continue;
            $value = trim((string) $input[$field]);
            if ($field === 'email') $value = strtolower($value);
            if ($field !== 'phone' && $value === '') $this->api->respond_error(ucfirst($field) . ' cannot be empty.', 422);
            if (strlen($value) > $maxLength || ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL))) {
                $this->api->respond_error('Invalid ' . $field . '.', 422);
            }
            $fields[] = $field . ' = ?';
            $values[] = $value ?: null;
        }

        if (isset($input['role'])) {
            if (!in_array($input['role'], ['resident', 'staff', 'admin'], true)) {
                $this->api->respond_error('Choose a valid user role.', 422);
            }
            if ((int) $id === (int) $actor['id'] && $input['role'] !== 'admin') {
                $this->api->respond_error('You cannot remove your own administrator role.', 409);
            }
            $fields[] = 'role = ?';
            $values[] = $input['role'];
        }
        if (array_key_exists('is_active', $input)) {
            $active = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ((int) $id === (int) $actor['id'] && !$active) {
                $this->api->respond_error('You cannot deactivate your own account.', 409);
            }
            $fields[] = 'is_active = ?';
            $values[] = $active;
        }
        if (array_key_exists('password', $input) && $input['password'] !== '') {
            if (!is_string($input['password']) || strlen($input['password']) < 8) {
                $this->api->respond_error('The password must contain at least 8 characters.', 422);
            }
            $fields[] = 'password = ?';
            $values[] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        if (!$fields) $this->api->respond_error('No user changes were provided.', 422);

        if ((int) $id !== (int) $actor['id'] && $user['role'] === 'admin'
            && (($input['role'] ?? 'admin') !== 'admin' || (isset($input['is_active']) && !filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN)))) {
            $remaining = $this->db->raw(
                'SELECT COUNT(*) AS total FROM users WHERE role = ? AND is_active = 1 AND id <> ?',
                ['admin', $id]
            )->fetch(PDO::FETCH_ASSOC);
            if ((int) $remaining['total'] === 0) $this->api->respond_error('At least one active administrator must remain.', 409);
        }

        $values[] = $id;
        try {
            $this->db->raw('UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?', $values);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') $this->api->respond_error('That email address is already in use.', 409);
            throw $error;
        }
        if (isset($input['role']) && $input['role'] !== 'resident' && $user['role'] === 'resident') {
            $this->db->raw('DELETE FROM resident_profiles WHERE user_id = ?', [$id]);
        }
        $this->actor = $actor;
        $this->audit('updated', 'users', $id, 'Updated user account ' . $id);
        $updated = $this->getUser($id);
        $updated['notification_settings'] = $this->getNotificationSettings($id);
        $this->respondSuccess($this->withProfilePhotoUrl($updated), 'User updated.');
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $actor = $this->requireAdmin();
        $user = $this->getUser($id);
        if (!$user) $this->api->respond_error('User not found.', 404);
        if ((int) $id === (int) $actor['id']) $this->api->respond_error('You cannot delete your own account.', 409);
        if ($user['role'] === 'admin') {
            $remaining = $this->db->raw(
                'SELECT COUNT(*) AS total FROM users WHERE role = ? AND is_active = 1 AND id <> ?',
                ['admin', $id]
            )->fetch(PDO::FETCH_ASSOC);
            if ((int) $remaining['total'] === 0) $this->api->respond_error('At least one active administrator must remain.', 409);
        }

        $references = [
            'reports' => 'resident_id',
            'report_comments' => 'user_id',
            'report_assignments' => 'staff_id',
            'community_report_comments' => 'user_id',
            'community_events' => 'created_by',
        ];
        foreach ($references as $table => $column) {
            $count = $this->db->raw(
                'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $column . ' = ?',
                [$id]
            )->fetch(PDO::FETCH_ASSOC);
            if ((int) $count['total'] > 0) {
                $this->api->respond_error('This account has existing records. Deactivate it instead of deleting it.', 409);
            }
        }
        $this->db->raw('DELETE FROM users WHERE id = ?', [$id]);
        $this->actor = $actor;
        $this->audit('deleted', 'users', $id, 'Deleted user account ' . $id);
        $this->respondSuccess(null, 'User deleted.');
    }
}
