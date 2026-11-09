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
        if ($this->db->raw(
            'SELECT id FROM email_verification_tokens WHERE user_id = ? LIMIT 1',
            [$user['id']]
        )->fetch(PDO::FETCH_ASSOC)) {
            $this->api->respond_error('Verify your email address before signing in.', 403);
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
        if (!SmtpMailer::isConfigured()) {
            $this->api->respond_error('Email verification is not configured. Configure the SMTP settings in baco360backend/.env.', 503);
        }
        if (!is_string($password) || strlen($password) < 8 || $password !== ($input['password_confirmation'] ?? null)) {
            $this->api->respond_error('Passwords must match and contain at least 8 characters.', 422);
        }
        $existingUser = $this->db->raw(
            'SELECT id, name, email_verified_at FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existingUser && $existingUser['email_verified_at'] !== null) {
            $this->api->respond_error('An account with this email already exists.', 409);
        }

        if ($existingUser) {
            $existingToken = $this->db->raw(
                'SELECT sent_at, (sent_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS sent_recently
                 FROM email_verification_tokens WHERE user_id = ? LIMIT 1',
                [$existingUser['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existingToken && (int) $existingToken['sent_recently'] === 1) {
                $this->respondSuccess(
                    ['email' => $email, 'verification_required' => true],
                    'A verification email was sent recently. Use the latest code in your inbox.'
                );
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!$this->db->transaction()) {
                $this->api->respond_error('Could not start email verification. Please try again.', 500);
            }
            try {
                $this->saveVerificationCode($existingUser['id'], $code);
                SmtpMailer::sendVerificationCode($email, $existingUser['name'], $code);
                $this->db->commit();
            } catch (Exception $error) {
                $this->db->roll_back();
                error_log('Could not resend verification during registration: ' . $error->getMessage());
                $this->api->respond_error('Could not send the verification email. Please try again.', 502);
            }
            $this->respondSuccess(
                ['email' => $email, 'verification_required' => true],
                'A new verification code has been sent to your email.'
            );
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not start account registration. Please try again.', 500);
        }
        try {
            $this->db->raw(
                'INSERT INTO users (name, email, phone, password, role, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [$name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), 'resident']
            );
            $user = $this->getUserByEmail($email);
            $this->db->raw(
                'INSERT INTO notification_settings (user_id, sms_enabled, email_enabled, report_updates_enabled, resolution_updates_enabled)
                 VALUES (?, ?, ?, 1, 1)',
                [$user['id'], !empty($input['sms_enabled']) ? 1 : 0, !empty($input['email_enabled']) ? 1 : 0]
            );
            $this->saveVerificationCode($user['id'], $code);
            SmtpMailer::sendVerificationCode($email, $name, $code);
            $this->db->commit();
        } catch (Exception $error) {
            $this->db->roll_back();
            error_log('Could not complete account registration and send verification email: ' . $error->getMessage());
            $this->api->respond_error('Could not complete account registration and send its verification email. Please check the SMTP configuration and try again.', 502);
        }
        $this->respondSuccess(
            ['email' => $email, 'verification_required' => true],
            'Account created. Check your email for the verification code.',
            201
        );
    }

    public function verifyEmail()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $email = strtolower(trim($input['email'] ?? ''));
        $code = trim($input['code'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            $this->api->respond_error('A valid email address and 6-digit code are required.', 422);
        }

        $verification = $this->db->raw(
            'SELECT u.id, t.code_hash, t.attempts, (t.expires_at > NOW()) AS code_is_valid
             FROM users u JOIN email_verification_tokens t ON t.user_id = u.id
             WHERE u.email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$verification || (int) $verification['code_is_valid'] !== 1) {
            $this->api->respond_error('This verification code has expired or is no longer active. Request a new code.', 422);
        }
        if ((int) $verification['attempts'] >= 5) {
            $this->api->respond_error('Too many incorrect codes. Request a new verification email.', 429);
        }
        if (!hash_equals($verification['code_hash'], hash('sha256', $code))) {
            $this->db->raw(
                'UPDATE email_verification_tokens SET attempts = attempts + 1 WHERE user_id = ?',
                [$verification['id']]
            );
            $this->api->respond_error('That code does not match the latest verification email. Use the newest code or request another one.', 422);
        }

        $this->db->raw('UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ?', [$verification['id']]);
        $this->db->raw('DELETE FROM email_verification_tokens WHERE user_id = ?', [$verification['id']]);
        $user = $this->getUser($verification['id']);
        $tokens = $this->api->issue_tokens(['id' => $user['id'], 'role' => $user['role']]);
        $this->respondSuccess([
            'user' => $this->userWithSettings($user),
            'tokens' => $tokens,
        ], 'Email verified and signed in.');
    }

    public function resendVerification()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $email = strtolower(trim($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('A valid email address is required.', 422);
        }
        if (!SmtpMailer::isConfigured()) {
            $this->api->respond_error('Email verification is not configured. Configure the SMTP settings in baco360backend/.env.', 503);
        }

        $verification = $this->db->raw(
            'SELECT u.id, u.name, t.sent_at,
                    (t.sent_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS sent_recently
             FROM users u JOIN email_verification_tokens t ON t.user_id = u.id
             WHERE u.email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if ($verification && (int) $verification['sent_recently'] === 1) {
            $this->api->respond_error('Please wait a minute before requesting another code.', 429);
        }
        if ($verification) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!$this->db->transaction()) {
                $this->api->respond_error('Could not start email verification. Please try again.', 500);
            }
            try {
                $this->saveVerificationCode($verification['id'], $code);
                SmtpMailer::sendVerificationCode($email, $verification['name'], $code);
                $this->db->commit();
            } catch (Exception $error) {
                $this->db->roll_back();
                error_log('Could not resend verification email: ' . $error->getMessage());
                $this->api->respond_error('The verification email could not be sent. Please try again later.', 502);
            }
        }

        $this->respondSuccess(null, 'If the account needs verification, a new code has been sent.');
    }

    public function forgotPassword()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $email = strtolower(trim($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Enter a valid email address.', 422);
        }
        if (!SmtpMailer::isConfigured()) {
            $this->api->respond_error('Password reset email is not configured.', 503);
        }

        $genericMessage = 'If a verified account matches that email, a password reset code has been sent.';
        $user = $this->db->raw(
            'SELECT id, name, email_verified_at, is_active FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user || !$user['email_verified_at'] || (int) $user['is_active'] !== 1) {
            $this->respondSuccess(null, $genericMessage);
        }

        $existingToken = $this->db->raw(
            'SELECT sent_at, (sent_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS sent_recently
             FROM password_reset_tokens WHERE user_id = ? LIMIT 1',
            [$user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existingToken && (int) $existingToken['sent_recently'] === 1) {
            $this->respondSuccess(null, $genericMessage);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not start password reset. Please try again.', 500);
        }
        try {
            $this->savePasswordResetCode($user['id'], $code);
            SmtpMailer::sendPasswordResetCode($email, $user['name'], $code);
            $this->db->commit();
        } catch (Exception $error) {
            $this->db->roll_back();
            error_log('Could not send password reset code: ' . $error->getMessage());
            $this->api->respond_error('Could not send the password reset email. Please try again.', 502);
        }

        $this->respondSuccess(null, $genericMessage);
    }

    public function resetPassword()
    {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $email = strtolower(trim($input['email'] ?? ''));
        $code = trim($input['code'] ?? '');
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            $this->api->respond_error('A valid email address and 6-digit code are required.', 422);
        }
        if (!is_string($password) || strlen($password) < 8 || $password !== $confirmation) {
            $this->api->respond_error('Passwords must match and contain at least 8 characters.', 422);
        }

        $reset = $this->db->raw(
            'SELECT u.id, t.code_hash, t.attempts, (t.expires_at > NOW()) AS code_is_valid
             FROM users u JOIN password_reset_tokens t ON t.user_id = u.id
             WHERE u.email = ? AND u.email_verified_at IS NOT NULL AND u.is_active = 1 LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$reset || (int) $reset['code_is_valid'] !== 1) {
            $this->api->respond_error('This reset code has expired or is no longer active. Request a new code.', 422);
        }
        if ((int) $reset['attempts'] >= 5) {
            $this->api->respond_error('Too many incorrect codes. Request a new password reset code.', 429);
        }
        if (!hash_equals($reset['code_hash'], hash('sha256', $code))) {
            $this->db->raw(
                'UPDATE password_reset_tokens SET attempts = attempts + 1 WHERE user_id = ?',
                [$reset['id']]
            );
            $this->api->respond_error('That code does not match. Check your email and try again.', 422);
        }

        if (!$this->db->transaction()) {
            $this->api->respond_error('Could not reset the password. Please try again.', 500);
        }
        try {
            $this->db->raw(
                'UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $reset['id']]
            );
            $this->db->raw('DELETE FROM password_reset_tokens WHERE user_id = ?', [$reset['id']]);
            $this->db->raw('DELETE FROM refresh_tokens WHERE user_id = ?', [$reset['id']]);
            $this->db->commit();
        } catch (Exception $error) {
            $this->db->roll_back();
            error_log('Could not reset account password: ' . $error->getMessage());
            $this->api->respond_error('Could not reset the password. Please try again.', 500);
        }

        $this->respondSuccess(null, 'Password reset successfully. Sign in with your new password.');
    }

    private function saveVerificationCode($userId, $code)
    {
        $this->db->raw(
            'INSERT INTO email_verification_tokens (user_id, code_hash, expires_at, sent_at, attempts)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW(), 0)
             ON DUPLICATE KEY UPDATE
                code_hash = VALUES(code_hash),
                expires_at = VALUES(expires_at),
                sent_at = NOW(),
                attempts = 0',
            [$userId, hash('sha256', $code)]
        );
    }

    private function savePasswordResetCode($userId, $code)
    {
        $this->db->raw(
            'INSERT INTO password_reset_tokens (user_id, code_hash, expires_at, sent_at, attempts)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW(), 0)
             ON DUPLICATE KEY UPDATE
                code_hash = VALUES(code_hash),
                expires_at = VALUES(expires_at),
                sent_at = NOW(),
                attempts = 0',
            [$userId, hash('sha256', $code)]
        );
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
            $details = strtolower($error->getMessage());
            if (str_contains($details, 'authentication')) {
                $message = 'The email provider rejected SMTP login. Check the credentials and complete any required account verification. For Gmail, use an App Password with 2-Step Verification enabled.';
            } elseif (str_contains($details, 'sender validation')
                || str_contains($details, 'recipient validation')
                || str_contains($details, 'resend rejected')) {
                $message = 'The email provider rejected delivery. Verify the sender domain and confirm it is allowed to send to this address.';
            } else {
                $message = 'The verification email could not be sent. Check the email provider configuration and try again later.';
            }
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
