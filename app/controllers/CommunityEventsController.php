<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class CommunityEventsController extends ApiBaseController
{
    public function index()
    {
        $this->authenticate();
        $events = $this->db->raw(
            'SELECT e.id, e.created_by, e.title, e.description, e.location, e.starts_at, e.ends_at,
                    e.created_at, u.name AS author_name
             FROM community_events e
             JOIN users u ON u.id = e.created_by
             ORDER BY e.starts_at, e.id
             LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as &$event) {
            $event['id'] = (int) $event['id'];
            $event['created_by'] = (int) $event['created_by'];
        }
        unset($event);
        $this->respondSuccess(['events' => $events]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $actor = $this->authenticate();
        $this->requirePublisher($actor);
        $input = $this->api->body();
        if (!is_array($input)) {
            $this->api->respond_error('Enter valid event details.', 422);
        }

        $titleValue = $input['title'] ?? null;
        $descriptionValue = $input['description'] ?? '';
        $locationValue = $input['location'] ?? '';
        $startsAtValue = $input['starts_at'] ?? null;
        $endsAtValue = $input['ends_at'] ?? null;
        if (!is_string($titleValue) || !is_string($descriptionValue) || !is_string($locationValue) || !is_string($startsAtValue)) {
            $this->api->respond_error('Enter valid event details.', 422);
        }
        if ($endsAtValue !== null && !is_string($endsAtValue)) {
            $this->api->respond_error('Choose a valid event end date and time.', 422);
        }

        $title = trim($titleValue);
        $description = trim($descriptionValue);
        $location = trim($locationValue);
        $startsAt = $this->parseDateTime($startsAtValue);
        $endsAt = $endsAtValue === null || $endsAtValue === ''
            ? null
            : $this->parseDateTime($endsAtValue);

        if ($title === '' || strlen($title) > 255) {
            $this->api->respond_error('Enter an event title of up to 255 characters.', 422);
        }
        if (strlen($description) > 5000) {
            $this->api->respond_error('The event description must be 5,000 characters or fewer.', 422);
        }
        if (strlen($location) > 255) {
            $this->api->respond_error('The event location must be 255 characters or fewer.', 422);
        }
        if ($startsAt === null) {
            $this->api->respond_error('Choose a valid event start date and time.', 422);
        }
        if ($endsAtValue !== null && $endsAtValue !== '' && $endsAt === null) {
            $this->api->respond_error('Choose a valid event end date and time.', 422);
        }
        if ($endsAt !== null && $endsAt < $startsAt) {
            $this->api->respond_error('The event end time must be after its start time.', 422);
        }

        $this->db->raw(
            'INSERT INTO community_events (created_by, title, description, location, starts_at, ends_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$actor['id'], $title, $description, $location !== '' ? $location : null, $startsAt, $endsAt]
        );
        $eventId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $auditWarning = false;
        try {
            $this->audit('created', 'community_events', $eventId, 'Created community event: ' . $title);
        } catch (Throwable $error) {
            $auditWarning = true;
            error_log('Community event ' . $eventId . ' was created but its audit record could not be saved: ' . $error->getMessage());
        }

        $event = $this->db->raw(
            'SELECT e.id, e.created_by, e.title, e.description, e.location, e.starts_at, e.ends_at,
                    e.created_at, u.name AS author_name
             FROM community_events e JOIN users u ON u.id = e.created_by
             WHERE e.id = ? LIMIT 1',
            [$eventId]
        )->fetch(PDO::FETCH_ASSOC);
        $event['id'] = (int) $event['id'];
        $event['created_by'] = (int) $event['created_by'];

        $this->respondSuccess(['event' => $event, 'audit_warning' => $auditWarning], 'Community event created.', 201);
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $actor = $this->authenticate();
        $this->requirePublisher($actor);
        if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
            $this->api->respond_error('Invalid event ID.', 422);
        }
        $event = $this->db->raw('SELECT id, created_by, title FROM community_events WHERE id = ? LIMIT 1', [$id])
            ->fetch(PDO::FETCH_ASSOC);
        if (!$event) $this->api->respond_error('Event not found.', 404);
        if ($actor['role'] === 'staff' && (int) $event['created_by'] !== (int) $actor['id']) {
            $this->api->respond_error('Staff may only delete events they created.', 403);
        }

        $this->db->raw('DELETE FROM community_events WHERE id = ?', [$id]);
        try {
            $this->audit('deleted', 'community_events', $id, 'Deleted community event: ' . $event['title']);
        } catch (Throwable $error) {
            error_log('Community event ' . $id . ' was deleted but its audit record could not be saved: ' . $error->getMessage());
        }
        $this->respondSuccess(null, 'Community event deleted.');
    }

    private function requirePublisher($actor)
    {
        if (!in_array($actor['role'], ['admin', 'staff'], true)) {
            $this->api->respond_error('Only administrators and staff may manage community events.', 403);
        }
    }

    private function parseDateTime($value)
    {
        if (!is_string($value)) return null;
        $date = DateTime::createFromFormat('!Y-m-d H:i:s', str_replace('T', ' ', $value));
        if (!$date || $date->format('Y-m-d H:i:s') !== str_replace('T', ' ', $value)) return null;
        return $date->format('Y-m-d H:i:s');
    }
}
