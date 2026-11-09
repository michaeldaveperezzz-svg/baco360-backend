
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: ReportModel
 * Handles reports for the Civic Reporting System.
 */
class ReportModel extends Model {

    protected $table = 'reports';
    protected $primary_key = 'id';

    protected $fillable = [
        'reference_number',
        'resident_id',
        'category_id',
        'title',
        'description',
        'status',
        'priority',
        'latitude',
        'longitude',
        'location_address',
        'rejection_reason',
        'resolution_description',
        'resolved_at',
        'submitted_at'
    ];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public function __construct()
    {
        parent::__construct();
    }
}
