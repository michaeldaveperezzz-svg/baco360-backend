<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/SmtpMailer.php';

class NotificationEmailService
{
    public static function queueReportSubmissionToAdmins($db, $reportId, $reference, array $report, $categoryName, $frontendUrl)
    {
        try {
            self::sendReportSubmissionToAdmins($db, $reportId, $reference, $report, $categoryName, $frontendUrl);
        } catch (Throwable $error) {
            error_log('Could not create admin report emails for ' . $reference . ': ' . $error->getMessage());
        }
    }

    public static function sendReportSubmissionToAdmins($db, $reportId, $reference, array $report, $categoryName, $frontendUrl)
    {
        $frontendUrl = rtrim((string) $frontendUrl, '/');
        $urlParts = parse_url($frontendUrl);
        if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)
            || !is_array($urlParts)
            || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
            || empty($urlParts['host'])) {
            error_log('Admin report email skipped: FRONTEND_URL is not a valid HTTP(S) URL.');
            return;
        }

        $adminIds = $db->raw(
            'SELECT id FROM users WHERE role = ? AND is_active = 1 ORDER BY id',
            ['admin']
        )->fetchAll(PDO::FETCH_COLUMN);
        $reportUrl = $frontendUrl . '/?report=' . rawurlencode((string) $reportId);
        $submittedAt = date('F j, Y g:i A');
        $subject = 'New community report: ' . $reference;
        $text = "A new community report was submitted.\n\n"
            . 'Reference: ' . $reference . "\nCategory: " . $categoryName . "\nTitle: " . $report['title'] . "\n"
            . 'Location: ' . $report['location_address'] . "\nSubmitted: " . $submittedAt . "\nView report: " . $reportUrl;
        $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCategory = htmlspecialchars($categoryName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = htmlspecialchars($report['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLocation = htmlspecialchars($report['location_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubmittedAt = htmlspecialchars($submittedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReportUrl = htmlspecialchars($reportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<h1>New community report</h1>'
            . '<p>A resident submitted a new report.</p>'
            . '<p><strong>Reference:</strong> ' . $safeReference . '<br>'
            . '<strong>Category:</strong> ' . $safeCategory . '<br>'
            . '<strong>Title:</strong> ' . $safeTitle . '<br>'
            . '<strong>Location:</strong> ' . $safeLocation . '<br>'
            . '<strong>Submitted:</strong> ' . $safeSubmittedAt . '</p>'
            . '<p><a href="' . $safeReportUrl . '">View report in Baco360</a></p>';

        foreach ($adminIds as $adminId) {
            try {
                self::send(
                    $db,
                    (int) $adminId,
                    $reportId,
                    'report-submitted:' . $reportId . ':admin:' . $adminId,
                    $subject,
                    $text,
                    $html
                );
            } catch (Throwable $error) {
                error_log('Could not create admin report email for ' . $reference . ': ' . $error->getMessage());
            }
        }
    }

    public static function queueAssignmentToStaff($db, $reportId, $staffId, $assignmentId, $reference, array $report, $frontendUrl)
    {
        try {
            self::sendAssignmentToStaff($db, $reportId, $staffId, $assignmentId, $reference, $report, $frontendUrl);
        } catch (Throwable $error) {
            error_log('Could not create assignment email for report ' . $reference . ': ' . $error->getMessage());
        }
    }

    public static function queueProgressUpdateToResident($db, $reportId, $residentId, $reference, array $report, $commentId, $comment, $frontendUrl)
    {
        try {
            self::sendProgressUpdateToResident($db, $reportId, $residentId, $reference, $report, $commentId, $comment, $frontendUrl);
        } catch (Throwable $error) {
            error_log('Could not create resident progress email for report ' . $reference . ': ' . $error->getMessage());
        }
    }

    public static function queueReassignmentToResident($db, $reportId, $residentId, $assignmentId, $reference, array $report, $previousStaffName, $newStaffName, $frontendUrl)
    {
        try {
            $frontendUrl = rtrim((string) $frontendUrl, '/');
            $urlParts = parse_url($frontendUrl);
            if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)
                || !is_array($urlParts)
                || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
                || empty($urlParts['host'])) {
                error_log('Resident reassignment email skipped: FRONTEND_URL is not a valid HTTP(S) URL.');
                return;
            }

            $resident = $db->raw(
                'SELECT id, name, email, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
                [$residentId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$resident || !filter_var($resident['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $reportUrl = $frontendUrl . '/?report=' . rawurlencode((string) $reportId);
            $subject = 'Staff update for your report: ' . $reference;
            $text = "The staff member handling your report has changed.\n\n"
                . 'Reference: ' . $reference . "\nTitle: " . $report['title']
                . "\nPrevious staff member: " . $previousStaffName . "\nNew staff member: " . $newStaffName
                . "\nView report: " . $reportUrl;
            $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeTitle = htmlspecialchars($report['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safePreviousStaff = htmlspecialchars((string) $previousStaffName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeNewStaff = htmlspecialchars((string) $newStaffName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeReportUrl = htmlspecialchars($reportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeResidentName = htmlspecialchars((string) ($resident['name'] ?? 'Resident'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<h1>Staff update for your report</h1>'
                . '<p>Hello ' . $safeResidentName . ',</p>'
                . '<p>The staff member handling your report has changed.</p>'
                . '<p><strong>Reference:</strong> ' . $safeReference . '<br>'
                . '<strong>Title:</strong> ' . $safeTitle . '<br>'
                . '<strong>Previous staff member:</strong> ' . $safePreviousStaff . '<br>'
                . '<strong>New staff member:</strong> ' . $safeNewStaff . '</p>'
                . '<p><a href="' . $safeReportUrl . '">Open report in Baco360</a></p>';

            self::send(
                $db,
                (int) $residentId,
                $reportId,
                'report-reassignment:' . $assignmentId . ':resident:' . $residentId,
                $subject,
                $text,
                $html,
                'report_updates_enabled'
            );
        } catch (Throwable $error) {
            error_log('Could not create resident reassignment email for report ' . $reference . ': ' . $error->getMessage());
        }
    }

    public static function queueAnnouncementToUser($db, $announcementId, $recipientId, $title, $message, $frontendUrl)
    {
        try {
            $frontendUrl = rtrim((string) $frontendUrl, '/');
            $urlParts = parse_url($frontendUrl);
            if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)
                || !is_array($urlParts)
                || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
                || empty($urlParts['host'])) {
                error_log('Announcement email skipped: FRONTEND_URL is not a valid HTTP(S) URL.');
                return;
            }

            $announcementUrl = $frontendUrl . '/?announcement=' . rawurlencode((string) $announcementId);
            $subject = 'Community announcement: ' . $title;
            $text = $title . "\n\n" . $message . "\n\nView announcements: " . $announcementUrl;
            $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $safeAnnouncementUrl = htmlspecialchars($announcementUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            self::send(
                $db,
                $recipientId,
                null,
                'announcement:' . $announcementId . ':user:' . $recipientId,
                $subject,
                $text,
                '<h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>'
                    . '<p><a href="' . $safeAnnouncementUrl . '">View announcements in Baco360</a></p>'
            );
        } catch (Throwable $error) {
            error_log('Could not create announcement email ' . $announcementId . ' for user ' . $recipientId . ': ' . $error->getMessage());
        }
    }

    public static function queueAddressVerificationToResident($db, $profileId, $recipientId, $status, $reviewerName, $frontendUrl)
    {
        try {
            if (!in_array($status, ['Verified', 'Rejected'], true)) {
                throw new InvalidArgumentException('The address verification status is invalid.');
            }

            $frontendUrl = rtrim((string) $frontendUrl, '/');
            $urlParts = parse_url($frontendUrl);
            if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)
                || !is_array($urlParts)
                || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
                || empty($urlParts['host'])) {
                error_log('Address verification email skipped: FRONTEND_URL is not a valid HTTP(S) URL.');
                return;
            }

            $isVerified = $status === 'Verified';
            $decision = $isVerified ? 'verified' : 'not verified';
            $subject = 'Update on your Baco360 address verification';
            $text = "Your address verification has been reviewed.\n\n"
                . 'Status: ' . $decision . "\nReviewed by: " . $reviewerName
                . "\nYou can sign in to Baco360 to view your profile.";
            $safeDecision = htmlspecialchars($decision, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeReviewer = htmlspecialchars($reviewerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $profileUrl = htmlspecialchars($frontendUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<h1>Address verification update</h1>'
                . '<p>Your address verification has been reviewed.</p>'
                . '<p><strong>Status:</strong> ' . $safeDecision . '<br>'
                . '<strong>Reviewed by:</strong> ' . $safeReviewer . '</p>'
                . '<p><a href="' . $profileUrl . '">Sign in to Baco360 to view your profile</a></p>';

            $result = self::send(
                $db,
                $recipientId,
                null,
                'address-verification:' . $profileId . ':' . strtolower($status) . ':' . bin2hex(random_bytes(8)),
                $subject,
                $text,
                $html
            );
            if (!in_array($result['status'] ?? '', ['sent', 'duplicate', 'skipped'], true)) {
                error_log('Address verification email for resident profile ' . $profileId . ' was not sent.');
            }
        } catch (Throwable $error) {
            error_log('Could not create address verification email for resident profile ' . $profileId . ': ' . $error->getMessage());
        }
    }

    public static function sendProgressUpdateToResident($db, $reportId, $residentId, $reference, array $report, $commentId, $comment, $frontendUrl)
    {
        $frontendUrl = rtrim((string) $frontendUrl, '/');
        $urlParts = parse_url($frontendUrl);
        if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)
            || !is_array($urlParts)
            || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
            || empty($urlParts['host'])) {
            error_log('Resident progress email skipped: FRONTEND_URL is not a valid HTTP(S) URL.');
            return;
        }

        $resident = $db->raw(
            'SELECT id, name, email, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
            [$residentId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$resident || !filter_var($resident['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $reportUrl = $frontendUrl . '/?report=' . rawurlencode((string) $reportId);
        $updatedAt = date('F j, Y g:i A');
        $subject = 'Report update: ' . $reference;
        $text = "A staff member posted an update to your report.\n\n"
            . 'Reference: ' . $reference . "\nTitle: " . $report['title'] . "\nLocation: " . $report['location_address'] . "\nUpdated: " . $updatedAt . "\nUpdate: " . $comment . "\nView report: " . $reportUrl;
        $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = htmlspecialchars($report['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLocation = htmlspecialchars($report['location_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUpdatedAt = htmlspecialchars($updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeComment = htmlspecialchars((string) $comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReportUrl = htmlspecialchars($reportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeResidentName = htmlspecialchars((string) ($resident['name'] ?? 'Resident'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<h1>Report update</h1>'
            . '<p>Hello ' . $safeResidentName . ',</p>'
            . '<p>A staff member posted an update on your report.</p>'
            . '<p><strong>Reference:</strong> ' . $safeReference . '<br>'
            . '<strong>Title:</strong> ' . $safeTitle . '<br>'
            . '<strong>Location:</strong> ' . $safeLocation . '<br>'
            . '<strong>Updated:</strong> ' . $safeUpdatedAt . '</p>'
            . '<p><strong>Update:</strong><br>' . $safeComment . '</p>'
            . '<p><a href="' . $safeReportUrl . '">Open report in Baco360</a></p>';

        self::send(
            $db,
            (int) $residentId,
            $reportId,
            'report-progress:' . $reportId . ':comment:' . $commentId . ':resident:' . $residentId,
            $subject,
            $text,
            $html,
            'report_updates_enabled'
        );
    }

    public static function queueStatusUpdateToResident($db, $reportId, $residentId, $reference, array $report, $oldStatus, $newStatus, $historyId, $statusDetails, $frontendUrl)
    {
        try {
            $frontendUrl = rtrim((string) $frontendUrl, '/');
            $urlParts = parse_url($frontendUrl);
            if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)
                || !is_array($urlParts)
                || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
                || empty($urlParts['host'])) {
                error_log('Resident status email skipped: FRONTEND_URL is not a valid HTTP(S) URL.');
                return;
            }

            $resident = $db->raw(
                'SELECT id, name, email, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
                [$residentId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$resident || !filter_var($resident['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $statusLabel = static function ($status) {
                return ucwords(str_replace('_', ' ', (string) $status));
            };
            $oldStatusLabel = $statusLabel($oldStatus);
            $newStatusLabel = $statusLabel($newStatus);
            $statusColors = [
                'submitted' => ['#475569', '#f1f5f9'],
                'under_review' => ['#1d4ed8', '#dbeafe'],
                'assigned' => ['#6d28d9', '#ede9fe'],
                'in_progress' => ['#c2410c', '#ffedd5'],
                'resolved' => ['#15803d', '#dcfce7'],
                'rejected' => ['#b91c1c', '#fee2e2'],
                'reopened' => ['#a16207', '#fef3c7'],
            ];
            [$statusTextColor, $statusBackgroundColor] = $statusColors[$newStatus] ?? ['#334155', '#e2e8f0'];
            $detailLabel = $newStatus === 'resolved' ? 'Resolution details' : ($newStatus === 'rejected' ? 'Reason' : null);
            $statusDetails = trim((string) $statusDetails);
            $reportUrl = $frontendUrl . '/?report=' . rawurlencode((string) $reportId);
            $updatedAt = date('F j, Y g:i A');
            $subject = 'Report status update: ' . $reference;
            $text = "The status of your report has changed.\n\n"
                . 'Reference: ' . $reference . "\nTitle: " . $report['title'] . "\nPrevious status: " . $oldStatusLabel
                . "\nNew status: " . $newStatusLabel . "\nUpdated: " . $updatedAt
                . ($detailLabel !== null && $statusDetails !== '' ? "\n" . $detailLabel . ': ' . $statusDetails : '')
                . "\nView report: " . $reportUrl;
            $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeTitle = htmlspecialchars($report['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeOldStatus = htmlspecialchars($oldStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeNewStatus = htmlspecialchars($newStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUpdatedAt = htmlspecialchars($updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeStatusDetails = htmlspecialchars($statusDetails, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeReportUrl = htmlspecialchars($reportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeResidentName = htmlspecialchars((string) ($resident['name'] ?? 'Resident'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<h1>Report status update</h1>'
                . '<p>Hello ' . $safeResidentName . ',</p>'
                . '<p>The status of your report has changed.</p>'
                . '<p><strong>Reference:</strong> ' . $safeReference . '<br>'
                . '<strong>Title:</strong> ' . $safeTitle . '<br>'
                . '<strong>Previous status:</strong> ' . $safeOldStatus . '<br>'
                . '<strong>New status:</strong> <span style="display:inline-block;padding:4px 10px;border-radius:999px;background-color:' . $statusBackgroundColor . ';color:' . $statusTextColor . ';font-weight:bold">' . $safeNewStatus . '</span><br>'
                . '<strong>Updated:</strong> ' . $safeUpdatedAt . '</p>'
                . ($detailLabel !== null && $statusDetails !== ''
                    ? '<p><strong>' . htmlspecialchars($detailLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':</strong><br>' . nl2br($safeStatusDetails) . '</p>'
                    : '')
                . '<p><a href="' . $safeReportUrl . '">Open report in Baco360</a></p>';

            self::send(
                $db,
                (int) $residentId,
                $reportId,
                'report-status:' . $reportId . ':history:' . $historyId . ':resident:' . $residentId,
                $subject,
                $text,
                $html,
                $newStatus === 'resolved' ? 'resolution_updates_enabled' : 'report_updates_enabled'
            );
        } catch (Throwable $error) {
            error_log('Could not create resident status email for report ' . $reference . ': ' . $error->getMessage());
        }
    }

    public static function sendAssignmentToStaff($db, $reportId, $staffId, $assignmentId, $reference, array $report, $frontendUrl)
    {
        $frontendUrl = rtrim((string) $frontendUrl, '/');
        $urlParts = parse_url($frontendUrl);
        if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)
            || !is_array($urlParts)
            || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
            || empty($urlParts['host'])) {
            error_log('Assignment email skipped: FRONTEND_URL is not a valid HTTP(S) URL.');
            return;
        }

        $staff = $db->raw(
            'SELECT id, name, email, is_active FROM users WHERE id = ? AND role = ? AND is_active = 1 LIMIT 1',
            [$staffId, 'staff']
        )->fetch(PDO::FETCH_ASSOC);
        if (!$staff || !filter_var($staff['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $reportUrl = $frontendUrl . '/?report=' . rawurlencode((string) $reportId);
        $assignedAt = date('F j, Y g:i A');
        $subject = 'Report assigned to you: ' . $reference;
        $text = "A report has been assigned to you.\n\n"
            . 'Reference: ' . $reference . "\nTitle: " . $report['title'] . "\nLocation: " . $report['location_address'] . "\nAssigned: " . $assignedAt . "\nView report: " . $reportUrl;
        $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = htmlspecialchars($report['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLocation = htmlspecialchars($report['location_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeAssignedAt = htmlspecialchars($assignedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReportUrl = htmlspecialchars($reportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeStaffName = htmlspecialchars((string) ($staff['name'] ?? 'Team member'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<h1>Report assigned</h1>'
            . '<p>Hello ' . $safeStaffName . ',</p>'
            . '<p>A report has been assigned to you.</p>'
            . '<p><strong>Reference:</strong> ' . $safeReference . '<br>'
            . '<strong>Title:</strong> ' . $safeTitle . '<br>'
            . '<strong>Location:</strong> ' . $safeLocation . '<br>'
            . '<strong>Assigned:</strong> ' . $safeAssignedAt . '</p>'
            . '<p><a href="' . $safeReportUrl . '">Open report in Baco360</a></p>';

        self::send(
            $db,
            (int) $staffId,
            $reportId,
            'report-assignment:' . $assignmentId . ':staff:' . $staffId,
            $subject,
            $text,
            $html,
            'report_updates_enabled'
        );
    }

    public static function send($db, $recipientId, $reportId, $eventKey, $subject, $text, $html, $preference = null)
    {
        if (!is_string($eventKey) || trim($eventKey) === '' || strlen($eventKey) > 191) {
            throw new InvalidArgumentException('A valid email event key is required.');
        }
        if ($reportId !== null && filter_var($reportId, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('A valid report ID is required.');
        }
        if (!in_array($preference, [null, 'report_updates_enabled', 'resolution_updates_enabled'], true)) {
            throw new InvalidArgumentException('The email preference type is invalid.');
        }
        if (!is_string($subject) || trim($subject) === '' || !is_string($text) || !is_string($html)) {
            throw new InvalidArgumentException('The email subject and message bodies are required.');
        }

        $user = $db->raw(
            'SELECT u.email, u.is_active,
                    COALESCE(s.email_enabled, 1) AS email_enabled,
                    COALESCE(s.report_updates_enabled, 1) AS report_updates_enabled,
                    COALESCE(s.resolution_updates_enabled, 1) AS resolution_updates_enabled
             FROM users u
             LEFT JOIN notification_settings s ON s.user_id = u.id
             WHERE u.id = ? LIMIT 1',
            [$recipientId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int) $user['is_active'] !== 1 || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'skipped'];
        }
        if ((int) $user['email_enabled'] !== 1 || ($preference !== null && (int) $user[$preference] !== 1)) {
            return ['status' => 'skipped'];
        }

        $existing = $db->raw(
            'SELECT id, status FROM notifications WHERE event_key = ? LIMIT 1',
            [$eventKey]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['status'] !== 'failed') {
                return ['status' => 'duplicate', 'notification_id' => (int) $existing['id']];
            }
            $claim = $db->raw(
                'UPDATE notifications SET status = ?, last_error = NULL
                 WHERE id = ? AND status = ?',
                ['pending', $existing['id'], 'failed']
            );
            if ($claim->rowCount() !== 1) {
                return ['status' => 'duplicate', 'notification_id' => (int) $existing['id']];
            }
            $notificationId = (int) $existing['id'];
        } else {
            try {
                $db->raw(
                    'INSERT INTO notifications
                        (user_id, report_id, event_key, channel, recipient, subject, message, status, provider, attempts, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())',
                    [$recipientId, $reportId, $eventKey, 'email', $user['email'], trim($subject), $text, 'pending', 'smtp']
                );
                $notificationId = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '23000') throw $error;
                $existing = $db->raw(
                    'SELECT id FROM notifications WHERE event_key = ? LIMIT 1',
                    [$eventKey]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$existing) throw $error;
                return ['status' => 'duplicate', 'notification_id' => (int) $existing['id']];
            }
        }

        try {
            SmtpMailer::sendEmail($user['email'], $subject, $text, $html);
            $db->raw(
                'UPDATE notifications
                 SET status = ?, attempts = attempts + 1, sent_at = NOW(), last_error = NULL
                 WHERE id = ?',
                ['sent', $notificationId]
            );
            return ['status' => 'sent', 'notification_id' => $notificationId];
        } catch (Throwable $error) {
            try {
                $db->raw(
                    'UPDATE notifications
                     SET status = ?, attempts = attempts + 1, last_error = ?
                     WHERE id = ?',
                    ['failed', substr($error->getMessage(), 0, 4000), $notificationId]
                );
            } catch (Throwable $loggingError) {
                error_log('Email delivery failed and its failure could not be logged: ' . $loggingError->getMessage());
            }
            return ['status' => 'failed', 'notification_id' => $notificationId];
        }
    }
}