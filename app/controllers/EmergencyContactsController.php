<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/ApiBaseController.php';

class EmergencyContactsController extends ApiBaseController
{
    public function index()
    {
        $this->api->require_method('GET');
        $contacts = $this->db->raw(
            'SELECT id, name, description, phone_number, sort_order
             FROM emergency_contacts ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($contacts as &$contact) {
            $contact['id'] = (int) $contact['id'];
            $contact['sort_order'] = (int) $contact['sort_order'];
        }
        unset($contact);
        $this->respondSuccess(['contacts' => $contacts]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $actor = $this->requireAdmin();
        $contact = $this->validatedContact($this->api->body());
        $sortOrder = (int) $this->db->raw(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM emergency_contacts'
        )->fetchColumn();
        $this->db->raw(
            'INSERT INTO emergency_contacts (name, description, phone_number, sort_order, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$contact['name'], $contact['description'], $contact['phone_number'], $sortOrder]
        );
        $contactId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->respondSuccess([
            'contact' => $this->findContact($contactId),
        ], $this->auditMessage($actor, 'created', $contactId), 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $actor = $this->requireAdmin();
        $contactId = $this->validateId($id);
        if (!$this->findContact($contactId)) {
            $this->api->respond_error('Emergency contact not found.', 404);
        }
        $contact = $this->validatedContact($this->api->body());
        $this->db->raw(
            'UPDATE emergency_contacts
             SET name = ?, description = ?, phone_number = ?, updated_at = NOW()
             WHERE id = ?',
            [$contact['name'], $contact['description'], $contact['phone_number'], $contactId]
        );
        $this->respondSuccess([
            'contact' => $this->findContact($contactId),
        ], $this->auditMessage($actor, 'updated', $contactId));
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $actor = $this->requireAdmin();
        $contactId = $this->validateId($id);
        if (!$this->findContact($contactId)) {
            $this->api->respond_error('Emergency contact not found.', 404);
        }
        $this->db->raw('DELETE FROM emergency_contacts WHERE id = ?', [$contactId]);
        $this->respondSuccess(null, $this->auditMessage($actor, 'deleted', $contactId));
    }

    private function validatedContact($input)
    {
        foreach (['name', 'phone_number'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                $this->api->respond_error('The ' . str_replace('_', ' ', $field) . ' field is required.', 422);
            }
        }
        $name = trim($input['name']);
        $phoneNumber = trim($input['phone_number']);
        $description = $input['description'] ?? '';
        if (!is_string($description)) {
            $this->api->respond_error('The description field must be text.', 422);
        }
        $description = trim($description);

        if ($name === '' || strlen($name) > 120) {
            $this->api->respond_error('Enter a contact name of up to 120 characters.', 422);
        }
        if ($phoneNumber === '' || strlen($phoneNumber) > 50
            || preg_match('/\A\+?[0-9][0-9\s().-]{0,48}\z/D', $phoneNumber) !== 1) {
            $this->api->respond_error('Enter a valid phone number of up to 50 characters.', 422);
        }
        if (strlen($description) > 255) {
            $this->api->respond_error('The description must be 255 characters or fewer.', 422);
        }
        return [
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'phone_number' => $phoneNumber,
        ];
    }

    private function validateId($id)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $this->api->respond_error('Invalid emergency contact ID.', 422);
        }
        return (int) $id;
    }

    private function findContact($id)
    {
        $contact = $this->db->raw(
            'SELECT id, name, description, phone_number, sort_order
             FROM emergency_contacts WHERE id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
        if ($contact) {
            $contact['id'] = (int) $contact['id'];
            $contact['sort_order'] = (int) $contact['sort_order'];
        }
        return $contact ?: null;
    }

    private function auditMessage($actor, $action, $contactId)
    {
        try {
            $this->audit($action, 'emergency_contact', $contactId, ucfirst($action) . ' emergency contact.', $actor['id']);
            return 'Emergency contact ' . $action . '.';
        } catch (Throwable $error) {
            error_log('Emergency contact was ' . $action . ', but its audit record could not be saved: ' . $error->getMessage());
            return 'Emergency contact ' . $action . ', but its audit record could not be saved.';
        }
    }
}
