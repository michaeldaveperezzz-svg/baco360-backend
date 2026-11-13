<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';
require_once APP_DIR . 'libraries/SmtpMailer.php';

class AuthController extends ApiBaseController
{
    public function login()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '') {
            $this->api->respond_error('A valid email and password are required.', 400);
        }

        $user = $this->db->raw(
            'SELECT id, name, email, phone, password, role, is_active, email_verified_at, profile_photo, created_at
             FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid email or password.', 401);
        }
        if ((int) $user['is_active'] !== 1) {
            $this->api->respond_error('Account is inactive.', 403);
        }
        $tokens = $this->api->issue_tokens(['id' => $user['id'], 'role' => $user['role']]);
        unset($user['password']);
        $user = $this->userWithSettings($user);
        $this->respondSuccess(['user' => $user, 'tokens' => $tokens], 'Login successful');
    }

    public function register()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $phone = trim($input['phone'] ?? '');
        $password = $input['password'] ?? '';

        if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $this->api->respond_error('Enter a valid name and email address.', 422);
        }
        if (!is_string($password) || strlen($password) < 8 || $password !== ($input['password_confirmation'] ?? null)) {
            $this->api->respond_error('Passwords must match and contain at least 8 characters.', 422);
        }
        $existingUser = $this->db->raw(
            'SELECT id FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existingUser) {
            $this->api->respond_error('An account with this email already exists.', 409);
        }

        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not start account registration. Please try again.', 500);
        }
        try {
            $this->db->raw(
                'INSERT INTO users (name, email, phone, password, role, is_active, email_verified_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())',
                [$name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), 'resident']
            );
            $user = $this->getUserByEmail($email);
            $this->db->raw(
                'INSERT INTO notification_settings (user_id, sms_enabled, email_enabled, report_updates_enabled, resolution_updates_enabled)
                 VALUES (?, ?, ?, 1, 1)',
                [
                    $user['id'],
                    !array_key_exists('sms_enabled', $input) || !empty($input['sms_enabled']) ? 1 : 0,
                    !array_key_exists('email_enabled', $input) || !empty($input['email_enabled']) ? 1 : 0,
                ]
            );
            $this->db->commit();
        } catch (Exception $error) {
            $this->db->roll_back();
            error_log('Could not complete account registration: ' . $error->getMessage());
            $this->api->respond_error('Could not complete account registration. Please try again.', 500);
        }
        $this->respondSuccess(
            ['email' => $email, 'verification_required' => false],
            'Account created. You can now sign in.',
            201
        );
    }

    public function googleSignIn()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $idToken = $input['id_token'] ?? '';
        $intent = $input['intent'] ?? '';
        $clientId = trim((string) getenv('GOOGLE_CLIENT_ID'));

        if (!is_string($idToken) || $idToken === '' || strlen($idToken) > 8192
            || !in_array($intent, ['sign_in', 'sign_up'], true)) {
            $this->api->respond_error('A Google credential and valid sign-in intent are required.', 422);
        }
        if ($clientId === '') {
            $this->api->respond_error('Google sign-in is not configured.', 503);
        }

        $identity = $this->verifyGoogleIdToken($idToken, $clientId);
        if (!$identity) {
            $this->api->respond_error('The Google credential is invalid or expired. Please try again.', 401);
        }

        $email = strtolower(trim($identity['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $this->api->respond_error('The Google account has an unsupported email address.', 422);
        }

        $user = $this->getUserByEmail($email);
        if (!$user && $intent === 'sign_in') {
            $this->api->respond_error('No Baco360 account is linked to this Google email. Create an account first.', 404);
        }

        if ($user) {
            if ((int) $user['is_active'] !== 1) {
                $this->api->respond_error('Account is inactive.', 403);
            }
            if (empty($user['email_verified_at'])) {
                $this->db->raw(
                    'UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$user['id']]
                );
            }
            $this->db->raw('DELETE FROM email_verification_tokens WHERE user_id = ?', [$user['id']]);
            $user = $this->getUser($user['id']);
        } else {
            if (!$this->db->transaction()) {
                $this->api->respond_error('Could not start Google account registration. Please try again.', 500);
            }
            try {
                $name = trim((string) ($identity['name'] ?? ''));
                if ($name === '') $name = strstr($email, '@', true) ?: 'Baco360 Resident';
                $name = substr($name, 0, 150);
                $this->db->raw(
                    'INSERT INTO users (name, email, phone, password, role, is_active, email_verified_at, created_at, updated_at)
                     VALUES (?, ?, NULL, ?, ?, 1, NOW(), NOW(), NOW())',
                    [$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'resident']
                );
                $user = $this->getUserByEmail($email);
                if (!$user) throw new RuntimeException('Google account was created but could not be loaded.');
                $this->db->raw(
                    'INSERT INTO notification_settings (user_id, sms_enabled, email_enabled, report_updates_enabled, resolution_updates_enabled)
                     VALUES (?, 0, 1, 1, 1)',
                    [$user['id']]
                );
                $this->db->commit();
            } catch (Throwable $error) {
                $this->db->roll_back();
                $user = $this->getUserByEmail($email);
                if (!$user) {
                    error_log('Could not create Google account: ' . $error->getMessage());
                    $this->api->respond_error('Could not create the Google account. Please try again.', 500);
                }
                if ((int) $user['is_active'] !== 1) {
                    $this->api->respond_error('Account is inactive.', 403);
                }
                $this->db->raw(
                    'UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$user['id']]
                );
                $user = $this->getUser($user['id']);
            }
        }

        $tokens = $this->api->issue_tokens(['id' => $user['id'], 'role' => $user['role']]);
        $this->respondSuccess([
            'user' => $this->userWithSettings($user),
            'tokens' => $tokens,
        ], 'Google sign-in successful.');
    }

    private function verifyGoogleIdToken($idToken, $clientId)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            error_log('Google token verification request failed.');
            $this->api->respond_error('Google sign-in is temporarily unavailable. Please try again.', 503);
        }

        $identity = json_decode($response, true);
        if (!is_array($identity)
            || !in_array($identity['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            || !hash_equals($clientId, (string) ($identity['aud'] ?? ''))
            || (isset($identity['azp']) && !hash_equals($clientId, (string) $identity['azp']))
            || (int) ($identity['exp'] ?? 0) <= time()
            || !in_array($identity['email_verified'] ?? false, [true, 'true', 1, '1'], true)
            || !is_string($identity['email'] ?? null)
            || !is_string($identity['sub'] ?? null)
            || $identity['sub'] === '') {
            return null;
        }

        return $identity;
    }

    public function me()
    {
        $this->respondSuccess($this->userWithSettings($this->authenticate()));
    }

    public function updateProfile()
    {
        $this->api->require_method('PUT');
        $actor = $this->authenticate();
        $input = $this->api->body();
        $fields = [];
        $values = [];

        if ($actor['role'] === 'resident' && (array_key_exists('email', $input) || array_key_exists('phone', $input))) {
            $currentUser = $this->getUser($actor['id']);
            $emailChanged = array_key_exists('email', $input)
                && strtolower(trim((string) $input['email'])) !== strtolower((string) $currentUser['email']);
            $requestedPhone = array_key_exists('phone', $input) ? trim((string) $input['phone']) : (string) ($currentUser['phone'] ?? '');
            $phoneChanged = array_key_exists('phone', $input)
                && ($requestedPhone ?: null) !== ($currentUser['phone'] !== null ? (string) $currentUser['phone'] : null);
            if ($emailChanged || $phoneChanged) {
                $this->api->respond_error('Email and phone changes require verification. Use the contact verification flow.', 422);
            }
        }

        foreach (['name' => 150, 'phone' => 30, 'email' => 150] as $field => $maxLength) {
            if (!array_key_exists($field, $input)) continue;
            $value = trim((string) $input[$field]);
            if ($field === 'email') $value = strtolower($value);
            if ($value === '' && $field !== 'phone') {
                $this->api->respond_error(ucfirst($field) . ' cannot be empty.', 422);
            }
            if (strlen($value) > $maxLength || ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL))) {
                $this->api->respond_error('Invalid ' . $field . '.', 422);
            }
            $fields[] = $field . ' = ?';
            $values[] = $value ?: null;
        }
        if (!$fields) $this->api->respond_error('No profile fields were provided.', 422);

        $values[] = $actor['id'];
        try {
            $this->db->raw('UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?', $values);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') $this->api->respond_error('That email address is already in use.', 409);
            throw $error;
        }
        $this->respondSuccess($this->userWithSettings($this->getUser($actor['id'])), 'Profile updated.');
    }

    public function requestContactChange()
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        $input = $this->api->body();
        $user = $this->getUser($actor['id']);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $phone = $phone === '' ? null : $phone;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $this->api->respond_error('Enter a valid email address.', 422);
        }
        if ($phone !== null && strlen($phone) > 30) {
            $this->api->respond_error('Phone number must be 30 characters or fewer.', 422);
        }

        $emailChanged = $email !== strtolower((string) $user['email']);
        $phoneChanged = $phone !== ($user['phone'] !== null ? (string) $user['phone'] : null);
        if (!$emailChanged && !$phoneChanged) {
            $this->api->respond_error('No contact details have changed.', 422);
        }
        if (empty($user['email_verified_at'])) {
            $this->api->respond_error('Verify your current email before changing contact details.', 403);
        }
        if (!SmtpMailer::isConfigured()) {
            $this->api->respond_error('Email verification is not configured. Contact details cannot be changed yet.', 503);
        }
        if ($emailChanged && $this->db->raw(
            'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
            [$email, $actor['id']]
        )->fetch(PDO::FETCH_ASSOC)) {
            $this->api->respond_error('That email address is already in use.', 409);
        }

        $existingRequest = $this->db->raw(
            'SELECT sent_at, (sent_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS sent_recently
             FROM user_contact_change_tokens WHERE user_id = ? LIMIT 1',
            [$actor['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existingRequest && (int) $existingRequest['sent_recently'] === 1) {
            $this->api->respond_error('Please wait a minute before requesting another verification code.', 429);
        }

        $accountCode = $phoneChanged ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : null;
        $emailCode = $emailChanged ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : null;
        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not start contact verification. Please try again.', 500);
        }
        try {
            $this->db->raw(
                'INSERT INTO user_contact_change_tokens
                    (user_id, pending_email, pending_phone, phone_change_requested, account_code_hash, email_code_hash, expires_at, sent_at, attempts)
                 VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW(), 0)
                 ON DUPLICATE KEY UPDATE
                    pending_email = VALUES(pending_email),
                    pending_phone = VALUES(pending_phone),
                    phone_change_requested = VALUES(phone_change_requested),
                    account_code_hash = VALUES(account_code_hash),
                    email_code_hash = VALUES(email_code_hash),
                    expires_at = VALUES(expires_at),
                    sent_at = NOW(),
                    attempts = 0',
                [
                    $actor['id'],
                    $emailChanged ? $email : null,
                    $phoneChanged ? $phone : null,
                    $phoneChanged ? 1 : 0,
                    hash('sha256', $accountCode ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)),
                    $emailCode !== null ? hash('sha256', $emailCode) : null,
                ]
            );
            if ($accountCode !== null) {
                SmtpMailer::sendAccountChangeCode($user['email'], $user['name'], $accountCode, 'account');
            }
            if ($emailCode !== null) {
                SmtpMailer::sendAccountChangeCode($email, $user['name'], $emailCode, 'email');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('Could not send profile contact verification: ' . $error->getMessage());
            $message = 'The verification email could not be sent. Check the MAIL_* SMTP configuration and try again later.';
            $this->api->respond_error($message, 502);
        }

        $this->respondSuccess([
            'email_verification_required' => $emailChanged,
            'phone_verification_required' => $phoneChanged,
        ], 'Verification code sent. Enter each code to confirm your contact changes.');
    }

    public function verifyContactChange()
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        $input = $this->api->body();
        $accountCode = trim((string) ($input['account_code'] ?? ''));
        $emailCode = trim((string) ($input['email_code'] ?? ''));
        $pending = $this->db->raw(
            'SELECT pending_email, pending_phone, phone_change_requested, account_code_hash, email_code_hash, attempts,
                    (expires_at > NOW()) AS code_is_valid
             FROM user_contact_change_tokens WHERE user_id = ? LIMIT 1',
            [$actor['id']]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$pending || (int) $pending['code_is_valid'] !== 1) {
            $this->api->respond_error('The verification codes have expired or are no longer active. Request new codes.', 422);
        }
        if ((int) $pending['attempts'] >= 5) {
            $this->api->respond_error('Too many incorrect codes. Request new verification codes.', 429);
        }
        $accountValid = (int) $pending['phone_change_requested'] !== 1
            || (preg_match('/^\d{6}$/', $accountCode)
                && hash_equals($pending['account_code_hash'], hash('sha256', $accountCode)));
        $emailValid = $pending['email_code_hash'] === null
            || (preg_match('/^\d{6}$/', $emailCode) && hash_equals($pending['email_code_hash'], hash('sha256', $emailCode)));
        if (!$accountValid || !$emailValid) {
            $this->db->raw(
                'UPDATE user_contact_change_tokens SET attempts = attempts + 1 WHERE user_id = ?',
                [$actor['id']]
            );
            $this->api->respond_error('One or more verification codes are incorrect.', 422);
        }

        if ($pending['pending_email'] !== null && $this->db->raw(
            'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
            [$pending['pending_email'], $actor['id']]
        )->fetch(PDO::FETCH_ASSOC)) {
            $this->api->respond_error('That email address is already in use. Request new codes after choosing another address.', 409);
        }

        $fields = [];
        $values = [];
        if ($pending['pending_email'] !== null) {
            $fields[] = 'email = ?';
            $fields[] = 'email_verified_at = NOW()';
            $values[] = $pending['pending_email'];
        }
        if ((int) $pending['phone_change_requested'] === 1) {
            $fields[] = 'phone = ?';
            $values[] = $pending['pending_phone'];
        }
        $values[] = $actor['id'];

        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not save your contact changes. Please try again.', 500);
        }
        try {
            $this->db->raw(
                'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?',
                $values
            );
            $this->db->raw('DELETE FROM user_contact_change_tokens WHERE user_id = ?', [$actor['id']]);
            $this->db->commit();
        } catch (PDOException $error) {
            $this->db->roll_back();
            if ($error->getCode() === '23000') {
                $this->api->respond_error('That email address is already in use.', 409);
            }
            throw $error;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->respondSuccess($this->userWithSettings($this->getUser($actor['id'])), 'Contact details verified and updated.');
    }

    public function changePassword()
    {
        $this->api->require_method('PUT');
        $actor = $this->authenticate();
        $input = $this->api->body();
        $currentPassword = $input['current_password'] ?? null;
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? null;
        if (!is_string($currentPassword) || $currentPassword === '' || !is_string($password) || strlen($password) < 8 || $password !== $confirmation) {
            $this->api->respond_error('Provide your current password and a matching new password of at least 8 characters.', 422);
        }

        $user = $this->db->raw('SELECT password FROM users WHERE id = ?', [$actor['id']])->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $this->api->respond_error('Your current password is incorrect.', 422);
        }

        $this->db->raw(
            'UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $actor['id']]
        );
        $this->respondSuccess(null, 'Password changed successfully.');
    }

    public function updateNotificationSettings()
    {
        $this->api->require_method('PUT');
        $actor = $this->authenticate();
        $input = $this->api->body();
        $allowed = ['sms_enabled', 'email_enabled', 'report_updates_enabled', 'resolution_updates_enabled'];
        $settings = $this->getNotificationSettings($actor['id']);
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) $settings[$key] = !empty($input[$key]) ? 1 : 0;
        }
        $this->db->raw(
            'INSERT INTO notification_settings (user_id, sms_enabled, email_enabled, report_updates_enabled, resolution_updates_enabled)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sms_enabled = VALUES(sms_enabled), email_enabled = VALUES(email_enabled),
                report_updates_enabled = VALUES(report_updates_enabled), resolution_updates_enabled = VALUES(resolution_updates_enabled),
                updated_at = NOW()',
            [$actor['id'], $settings['sms_enabled'], $settings['email_enabled'], $settings['report_updates_enabled'], $settings['resolution_updates_enabled']]
        );
        $this->respondSuccess($this->userWithSettings($this->getUser($actor['id'])), 'Notification settings updated.');
    }

    public function logout()
    {
        $this->api->require_method('POST');
        $this->authenticate();
        $input = $this->api->body();
        if (!empty($input['refresh_token'])) $this->api->revoke_refresh_token($input['refresh_token']);
        $this->respondSuccess(null, 'Logged out successfully.');
    }

    public function refresh()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $refreshToken = $input['refresh_token'] ?? '';
        if (!is_string($refreshToken) || $refreshToken === '') {
            $this->api->respond_error('A refresh token is required.', 400);
        }
        $this->api->refresh_access_token($refreshToken);
    }

    private function getUserByEmail($email)
    {
        return $this->db->raw(
            'SELECT id, name, email, phone, role, is_active, email_verified_at, profile_photo, created_at
             FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function userWithSettings($user)
    {
        $user['notification_settings'] = $this->getNotificationSettings($user['id']);
        return $this->withProfilePhotoUrl($user);
    }
}
