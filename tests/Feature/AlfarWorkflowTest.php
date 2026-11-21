<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access;
use App\Services\Attendance;
use App\Services\Performance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class AlfarWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $worker;

    private User $supervisor;

    private int $employee;

    private int $supervisorEmployee;

    private int $department;

    private int $position;

    private int $shift;

    private int $period;

    private int $metric;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00'));
        $this->withoutVite();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_superadmin' => true])->save();
        $this->supervisor = User::factory()->create();
        $this->worker = User::factory()->create();
        $this->department = DB::table('departments')->insertGetId(['name' => 'Produksi']);
        $this->position = DB::table('positions')->insertGetId(['department_id' => $this->department, 'name' => 'Operator']);
        $this->shift = DB::table('shifts')->insertGetId(['name' => 'Pagi', 'starts_at' => '08:00', 'ends_at' => '17:00', 'tolerance_minutes' => 10]);
        $this->supervisorEmployee = DB::table('employees')->insertGetId(['user_id' => $this->supervisor->id, 'nik' => 'SUP1', 'position_id' => $this->position]);
        $this->employee = DB::table('employees')->insertGetId(['user_id' => $this->worker->id, 'nik' => 'OP1', 'position_id' => $this->position, 'supervisor_id' => $this->supervisorEmployee]);
        DB::table('schedules')->insert(['employee_id' => $this->employee, 'shift_id' => $this->shift, 'work_date' => '2026-11-17', 'scheduled_in' => '2026-11-17 08:00:00', 'scheduled_out' => '2026-11-17 17:00:00', 'tolerance_minutes' => 10]);
        $this->period = DB::table('periods')->insertGetId(['name' => 'November 2026', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'planning']);
        $this->metric = DB::table('metrics')->insertGetId(['position_id' => $this->position, 'name' => 'Akurasi packing', 'unit' => '%', 'direction' => 'higher', 'level' => 'individual', 'source' => 'QC per operator', 'score_cap' => 120]);
    }

    private function grant(User $user, string $permission, string $scope): void
    {
        $role = DB::table('roles')->insertGetId(['name' => $permission.$user->id.$scope]);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);
        DB::table('role_grants')->insert(['role_id' => $role, 'permission' => $permission, 'scope' => $scope]);
    }

    private function target(): int
    {
        return DB::table('targets')->insertGetId(['period_id' => $this->period, 'metric_id' => $this->metric, 'employee_id' => $this->employee, 'target' => 99, 'weight' => 100, 'definition' => json_encode((array) DB::table('metrics')->find($this->metric))]);
    }

    public function test_domain_is_enforced_when_creating_and_changing_accounts(): void
    {
        $this->actingAs($this->admin)->postJson('/manage/accounts', ['name' => 'Invalid', 'email' => 'a@sub.alfar.id', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($this->worker)->patchJson('/settings/profile', ['name' => 'Operator', 'email' => 'a@alfar.id.evil.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->post('/register', [])->assertNotFound();
    }

    public function test_temporary_password_blocks_work_until_changed(): void
    {
        $this->worker->forceFill(['must_change_password' => true])->save();
        $this->actingAs($this->worker)->get('/dashboard')->assertRedirect('/password/change');
        $this->post('/password/change', ['current_password' => 'password', 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'])->assertRedirect('/dashboard');
        $this->assertFalse($this->worker->fresh()->must_change_password);
        $this->get('/dashboard')->assertOk();
    }

    public function test_account_management_does_not_grant_performance_visibility(): void
    {
        $this->target();
        $this->grant($this->supervisor, 'accounts.manage', 'all');
        $this->actingAs($this->supervisor)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->has('accounts', 3)->has('reports', 1)->where('reports.0.employee.id', $this->supervisorEmployee));
        $this->assertFalse(app(Access::class)->allows($this->supervisor, 'reports.read', $this->employee));
        $this->postJson('/manage/roles', ['name' => 'Escalation', 'grants' => []])->assertForbidden();
    }

    public function test_report_scope_is_limited_to_direct_reports_and_changes_take_effect(): void
    {
        $this->grant($this->supervisor, 'reports.read', 'reports');
        $access = app(Access::class);
        $this->assertTrue($access->allows($this->supervisor, 'reports.read', $this->employee));
        $this->assertFalse($access->allows($this->supervisor, 'reports.read', $this->supervisorEmployee));
        DB::table('role_grants')->update(['scope' => 'self']);
        $this->assertFalse($access->allows($this->supervisor, 'reports.read', $this->employee));
    }

    private function scannerDevice(string $type = 'scanner'): int
    {
        return DB::table('devices')->insertGetId(['name' => 'Pemindai kantor', 'type' => $type, 'token_hash' => hash('sha256', random_bytes(32))]);
    }

    private function personalToken(): string
    {
        $url = $this->actingAs($this->worker)->postJson('/attendance/qr')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['employee_qr'];
    }

    public function test_personal_qr_is_scanned_by_office_and_is_single_use_and_expires(): void
    {
        $device = $this->scannerDevice();
        $token = $this->personalToken();
        $payload = ['token' => $token, 'device_id' => $device, 'input_method' => 'laptop_camera', 'event' => 'in'];
        $this->actingAs($this->admin)->postJson('/attendance/scan', $payload)->assertOk()->assertJsonPath('employee_name', $this->worker->name);
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee, 'mode' => 'wfo', 'source' => 'laptop_camera', 'clock_in' => '2026-11-17 08:00:00']);
        $this->postJson('/attendance/scan', [...$payload, 'event' => 'out'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $token = $this->personalToken();
        $this->travel(16)->seconds();
        $this->actingAs($this->admin)->postJson('/attendance/scan', [...$payload, 'token' => $token])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertDatabaseCount('qr_uses', 1);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'action' => 'qr.scan']);
    }

    public function test_employee_can_only_display_own_qr_and_cannot_self_scan_or_skip_wfh_approval(): void
    {
        $device = $this->scannerDevice();
        $token = $this->personalToken();
        $this->assertDatabaseHas('qr_tokens', ['employee_id' => $this->employee, 'issued_by' => $this->worker->id]);
        $this->postJson('/attendance/scan', ['token' => $token, 'device_id' => $device, 'input_method' => 'laptop_camera', 'event' => 'in'])->assertForbidden();
        $this->postJson('/attendance/clock', ['mode' => 'wfo', 'event' => 'in', 'token' => $token])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->postJson('/attendance/clock', ['mode' => 'wfh', 'event' => 'in', 'work_date' => '2026-11-17'])->assertUnprocessable()->assertJsonValidationErrors('attendance');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_new_qr_invalidates_previous_qr_and_cannot_be_reassigned_by_request(): void
    {
        $old = $this->personalToken();
        $url = $this->actingAs($this->worker)->postJson('/attendance/qr', ['employee_id' => $this->supervisorEmployee])->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertDatabaseHas('qr_tokens', ['token_hash' => hash('sha256', $query['employee_qr']), 'employee_id' => $this->employee]);
        $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $old, 'device_id' => $this->scannerDevice(), 'input_method' => 'laptop_camera', 'event' => 'in'])->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_scanner_scope_is_enforced_before_returning_employee_information(): void
    {
        $token = $this->personalToken();
        $device = $this->scannerDevice();
        $this->grant($this->supervisor, 'attendance.scan', 'self');
        $this->actingAs($this->supervisor)->postJson('/attendance/scan', ['token' => $token, 'device_id' => $device, 'input_method' => 'laptop_camera', 'event' => 'in'])->assertForbidden();
        $this->assertDatabaseCount('qr_uses', 0);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_usb_scanner_and_phone_camera_record_in_and_out_for_the_qr_owner(): void
    {
        $device = $this->scannerDevice();
        $phone = $this->scannerDevice('phone_camera');
        $token = $this->personalToken();
        $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $token, 'device_id' => $device, 'input_method' => 'usb_scanner', 'event' => 'in'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 17:00:00'));
        $token = $this->personalToken();
        $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $token, 'device_id' => $phone, 'input_method' => 'phone_camera', 'event' => 'out'])->assertOk();
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee, 'clock_out' => '2026-11-17 17:00:00', 'source' => 'phone_camera']);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_inactive_or_wrong_type_devices_and_disabled_employee_cannot_scan(): void
    {
        $device = $this->scannerDevice();
        $token = $this->personalToken();
        $payload = ['token' => $token, 'device_id' => $device, 'input_method' => 'phone_camera', 'event' => 'in'];
        $this->actingAs($this->admin)->postJson('/attendance/scan', $payload)->assertUnprocessable();
        DB::table('devices')->where('id', $device)->update(['active' => false]);
        $this->postJson('/attendance/scan', [...$payload, 'input_method' => 'laptop_camera'])->assertUnprocessable();
        DB::table('devices')->where('id', $device)->update(['active' => true]);
        $this->worker->forceFill(['active' => false])->save();
        $this->postJson('/attendance/scan', [...$payload, 'input_method' => 'laptop_camera'])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_missing_schedule_does_not_consume_qr_and_fresh_qr_cannot_duplicate_clock_in(): void
    {
        $device = $this->scannerDevice();
        $token = $this->personalToken();
        $payload = ['token' => $token, 'device_id' => $device, 'input_method' => 'laptop_camera', 'event' => 'in'];
        DB::table('schedules')->where('employee_id', $this->employee)->delete();
        $this->actingAs($this->admin)->postJson('/attendance/scan', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('qr_uses', 0);
        $this->post('/manage/schedules', ['employee_id' => $this->employee, 'shift_id' => $this->shift, 'work_date' => '2026-11-17'])->assertRedirect();
        $this->postJson('/attendance/scan', $payload)->assertOk();
        $token = $this->personalToken();
        $this->actingAs($this->admin)->postJson('/attendance/scan', [...$payload, 'token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('qr_uses', 1);
    }

    public function test_qr_without_employee_binding_and_non_wfo_accounts_are_rejected(): void
    {
        $token = str_repeat('b', 64);
        $device = $this->scannerDevice();
        DB::table('qr_tokens')->insert(['token_hash' => hash('sha256', $token), 'issued_by' => $this->admin->id, 'expires_at' => now()->addSeconds(15)]);
        $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $token, 'device_id' => $device, 'input_method' => 'laptop_camera', 'event' => 'in'])->assertUnprocessable();
        DB::table('employees')->where('id', $this->employee)->update(['allow_wfo' => false]);
        $this->actingAs($this->worker)->postJson('/attendance/qr')->assertForbidden();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_wfh_requires_approval_unless_account_has_direct_access(): void
    {
        $this->actingAs($this->worker)->post('/attendance/requests', ['type' => 'wfh', 'work_date' => '2026-11-17', 'reason' => 'Pekerjaan laporan dari rumah'])->assertRedirect();
        $id = DB::table('requests')->value('id');
        $this->grant($this->supervisor, 'attendance.approve', 'reports');
        $this->actingAs($this->supervisor)->post('/attendance/requests/'.$id.'/decide', ['status' => 'approved', 'decision_note' => 'Disetujui untuk hari ini'])->assertRedirect();
        $this->actingAs($this->worker)->post('/attendance/clock', ['mode' => 'wfh', 'event' => 'in', 'work_date' => '2026-11-17'])->assertRedirect();
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee, 'mode' => 'wfh']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.decide']);
    }

    public function test_wfh_enabled_account_can_clock_without_request(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['allow_wfh' => true]);
        $this->actingAs($this->worker)->post('/attendance/clock', ['mode' => 'wfh', 'event' => 'in', 'work_date' => '2026-11-17'])->assertRedirect();
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('requests', 0);
    }

    public function test_night_shift_and_tolerance_are_calculated_from_frozen_schedule(): void
    {
        DB::table('schedules')->where('employee_id', $this->employee)->update(['scheduled_in' => '2026-11-17 22:00:00', 'scheduled_out' => '2026-11-18 06:00:00']);
        $service = app(Attendance::class);
        $service->record($this->employee, 'in', 'wfo', 'excel', CarbonImmutable::parse('2026-11-17 22:12:00'), '2026-11-17');
        $result = $service->record($this->employee, 'out', 'wfo', 'fingerprint', CarbonImmutable::parse('2026-11-18 05:55:00'), '2026-11-17');
        $this->assertSame(2, $result->late_minutes);
        $this->assertSame(5, $result->early_minutes);
        $this->assertSame('2026-11-17', $result->work_date);
    }

    public function test_fingerprint_is_authenticated_mapped_and_idempotent(): void
    {
        $token = str_repeat('a', 64);
        $device = DB::table('devices')->insertGetId(['name' => 'Gate A', 'token_hash' => hash('sha256', $token)]);
        DB::table('device_employees')->insert(['device_id' => $device, 'employee_id' => $this->employee, 'external_id' => '0042']);
        $event = ['external_event_id' => 'evt-001', 'external_id' => '0042', 'event' => 'in', 'occurred_at' => '2026-11-17 08:00:00', 'work_date' => '2026-11-17'];
        $this->postJson('/api/fingerprint/events', $event)->assertUnauthorized();
        $this->withToken($token)->postJson('/api/fingerprint/events', $event)->assertOk()->assertJsonPath('status', 'recorded');
        $this->withToken($token)->postJson('/api/fingerprint/events', $event)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseCount('device_events', 1);
        $this->assertDatabaseHas('attendances', ['source' => 'fingerprint', 'mode' => 'wfo']);
    }

    public function test_excel_import_is_atomic_and_reports_missing_nik(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'alfar').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['nik', 'work_date', 'clock_in', 'clock_out', 'mode']));
        $writer->addRow(Row::fromValues(['OP1', '2026-11-17', '2026-11-17 08:00:00', '2026-11-17 17:00:00', 'wfo']));
        $writer->addRow(Row::fromValues(['MISSING', '2026-11-17', '2026-11-17 08:00:00', '', 'wfo']));
        $writer->close();
        $file = new UploadedFile($path, 'absensi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($this->admin)->postJson('/attendance/import', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('employee_notifications', 0);
        unlink($path);
    }

    public function test_excel_import_records_real_xlsx_and_rejects_duplicates(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'alfar').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['nik', 'work_date', 'clock_in', 'clock_out', 'mode']));
        $writer->addRow(Row::fromValues(['OP1', '2026-11-17', '2026-11-17 08:12:00', '2026-11-17 16:55:00', 'wfo']));
        $writer->close();
        $file = new UploadedFile($path, 'absensi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($this->admin)->post('/attendance/import', ['file' => $file])->assertRedirect();
        $this->assertDatabaseHas('attendances', ['late_minutes' => 2, 'early_minutes' => 5, 'source' => 'excel']);
        $this->postJson('/attendance/import', ['file' => $file])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 1);
        unlink($path);
    }

    public function test_excel_import_accepts_account_email_without_employee_number(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'alfar').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['email', 'work_date', 'clock_in', 'clock_out', 'mode']));
        $writer->addRow(Row::fromValues([strtoupper($this->worker->email), '2026-11-17', '2026-11-17 08:00:00', '2026-11-17 17:00:00', 'wfo']));
        $writer->close();
        try {
            $file = new UploadedFile($path, 'absensi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $this->actingAs($this->admin)->post('/attendance/import', ['file' => $file])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee, 'source' => 'excel', 'clock_out' => '2026-11-17 17:00:00']);
        } finally {
            unlink($path);
        }
    }

    public function test_targets_are_locked_after_period_activation_and_weights_must_total_100(): void
    {
        $target = $this->target();
        DB::table('targets')->where('id', $target)->update(['weight' => 50]);
        $this->actingAs($this->admin)->postJson('/manage/period-status', ['period_id' => $this->period, 'status' => 'active'])->assertUnprocessable();
        DB::table('targets')->where('id', $target)->update(['weight' => 100]);
        $this->post('/manage/period-status', ['period_id' => $this->period, 'status' => 'active'])->assertRedirect();
        $this->postJson('/manage/targets', ['period_id' => $this->period, 'metric_id' => $this->metric, 'employee_id' => $this->employee, 'target' => 99, 'weight' => 100])->assertUnprocessable();
    }

    public function test_monthly_review_cycle_publishes_snapshot_and_keeps_revision_audit(): void
    {
        Storage::fake('local');
        $id = $this->target();
        DB::table('periods')->where('id', $this->period)->update(['status' => 'active']);
        $this->grant($this->worker, 'kpi.submit', 'self');
        $this->grant($this->supervisor, 'kpi.validate', 'reports');
        $this->grant($this->supervisor, 'reviews.publish', 'reports');
        $this->actingAs($this->worker)->post('/targets/'.$id.'/submit', ['actual' => 97.8, 'note' => 'Kesalahan label', 'evidence' => UploadedFile::fake()->create('qc.pdf', 10, 'application/pdf')])->assertRedirect();
        $this->actingAs($this->supervisor)->post('/targets/'.$id.'/validate', ['status' => 'validated', 'reason' => 'Sesuai rekap QC'])->assertRedirect();
        DB::table('periods')->where('id', $this->period)->update(['status' => 'review']);
        $review = ['employee_id' => $this->employee, 'period_id' => $this->period, 'analysis' => 'Kesalahan dominan pada label.', 'follow_up' => 'Pendampingan pengecekan label', 'due_on' => '2026-12-01', 'publish' => true];
        $this->post('/reviews', $review)->assertRedirect();
        $saved = DB::table('reviews')->first();
        $snapshot = json_decode($saved->snapshot, true);
        $this->assertEquals(98.79, $snapshot['score']);
        $this->assertSame('published', $saved->status);
        $this->postJson('/reviews', [...$review, 'analysis' => 'Revisi tanpa alasan'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->post('/reviews', [...$review, 'analysis' => 'Perbaikan catatan', 'reason' => 'Klarifikasi hasil diskusi'])->assertRedirect();
        $this->assertDatabaseHas('reviews', ['revision' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.publish', 'reason' => 'Klarifikasi hasil diskusi']);
        $this->actingAs($this->worker)->post('/reviews/'.$saved->id.'/respond', ['response' => 'Saya setuju dengan tindak lanjut.'])->assertRedirect();
        $this->assertDatabaseHas('reviews', ['response' => 'Saya setuju dengan tindak lanjut.']);
    }

    public function test_missing_or_unvalidated_kpi_cannot_be_published(): void
    {
        $this->target();
        DB::table('periods')->where('id', $this->period)->update(['status' => 'review']);
        $this->actingAs($this->admin)->postJson('/reviews', ['employee_id' => $this->employee, 'period_id' => $this->period, 'analysis' => 'Analisis', 'follow_up' => 'Pendampingan', 'due_on' => '2026-12-01', 'publish' => true])->assertUnprocessable()->assertJsonValidationErrors('publish');
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_lower_is_better_uses_cap_at_zero_and_team_is_separate(): void
    {
        $id = $this->target();
        $definition = (array) DB::table('metrics')->find($this->metric);
        $definition['direction'] = 'lower';
        DB::table('targets')->where('id', $id)->update(['target' => 2, 'definition' => json_encode($definition)]);
        DB::table('submissions')->insert(['target_id' => $id, 'actual' => 0, 'note' => 'Tidak ada reject', 'status' => 'validated', 'submitted_by' => $this->worker->id, 'validated_by' => $this->supervisor->id]);
        $definition['level'] = 'team';
        DB::table('targets')->insert(['period_id' => $this->period, 'metric_id' => $this->metric, 'department_id' => $this->department, 'target' => 10, 'weight' => 100, 'definition' => json_encode($definition)]);
        $report = app(Performance::class)->report($this->employee, $this->period);
        $this->assertEquals(120, $report['score']);
        $this->assertCount(2, $report['targets']);
        $this->assertEquals(100, $report['weight']);
    }

    public function test_master_data_updates_preserve_frozen_schedules_and_account_domain(): void
    {
        $this->actingAs($this->admin)->post('/manage/shifts', ['id' => $this->shift, 'name' => 'Pagi', 'starts_at' => '09:00', 'ends_at' => '18:00', 'tolerance_minutes' => 30])->assertRedirect();
        $this->assertDatabaseHas('shifts', ['starts_at' => '09:00', 'tolerance_minutes' => 30]);
        $this->assertDatabaseHas('schedules', ['scheduled_in' => '2026-11-17 08:00:00', 'tolerance_minutes' => 10]);
        $this->postJson('/manage/accounts', ['id' => $this->worker->id, 'name' => 'Operator', 'email' => 'operator@sub.alfar.id', 'active' => true])->assertUnprocessable();
        $this->post('/manage/accounts', ['id' => $this->worker->id, 'name' => 'Operator', 'email' => 'OPERATOR@alfar.id', 'active' => false])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $this->worker->id, 'email' => 'operator@alfar.id', 'active' => false]);
        $this->actingAs($this->worker->fresh())->get('/dashboard')->assertForbidden();
    }

    public function test_target_edits_work_only_during_planning(): void
    {
        $target = $this->target();
        $data = ['id' => $target, 'period_id' => $this->period, 'metric_id' => $this->metric, 'employee_id' => $this->employee, 'target' => 98, 'weight' => 100];
        $this->actingAs($this->admin)->post('/manage/targets', $data)->assertRedirect();
        $this->assertDatabaseHas('targets', ['id' => $target, 'target' => 98]);
        DB::table('periods')->where('id', $this->period)->update(['status' => 'active']);
        $this->postJson('/manage/targets', [...$data, 'target' => 90])->assertUnprocessable();
        $this->assertDatabaseHas('targets', ['id' => $target, 'target' => 98]);
    }

    public function test_corrected_kpi_keeps_published_snapshot_until_review_is_reissued(): void
    {
        Storage::fake('local');
        $target = $this->target();
        DB::table('submissions')->insert(['target_id' => $target, 'actual' => 99, 'note' => 'Awal', 'status' => 'validated', 'submitted_by' => $this->worker->id, 'validated_by' => $this->supervisor->id]);
        DB::table('periods')->where('id', $this->period)->update(['status' => 'review']);
        $review = ['employee_id' => $this->employee, 'period_id' => $this->period, 'analysis' => 'Awal', 'follow_up' => 'Pertahankan', 'due_on' => '2026-12-01', 'publish' => true];
        $this->actingAs($this->admin)->post('/reviews', $review)->assertRedirect();
        $original = DB::table('reviews')->value('snapshot');
        $this->post('/targets/'.$target.'/correct', ['actual' => 97, 'note' => 'Perbaikan rekap', 'reason' => 'QC mengoreksi jumlah label', 'evidence' => UploadedFile::fake()->create('qc-revisi.pdf', 10, 'application/pdf')])->assertRedirect();
        $this->assertSame($original, DB::table('reviews')->value('snapshot'));
        $this->assertDatabaseHas('submissions', ['target_id' => $target, 'actual' => 97]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kpi.correct', 'reason' => 'QC mengoreksi jumlah label']);
        $this->post('/reviews', [...$review, 'analysis' => 'Angka dikoreksi', 'reason' => 'Rekap QC diperbarui'])->assertRedirect();
        $this->assertNotSame($original, DB::table('reviews')->value('snapshot'));
    }

    public function test_supervisor_cannot_validate_or_review_an_unrelated_employee(): void
    {
        $target = $this->target();
        $this->grant($this->supervisor, 'reviews.publish', 'self');
        $this->grant($this->supervisor, 'kpi.validate', 'self');
        $this->actingAs($this->supervisor)->postJson('/reviews', ['employee_id' => $this->employee, 'period_id' => $this->period, 'analysis' => 'x', 'follow_up' => 'y', 'due_on' => '2026-12-01', 'publish' => true])->assertForbidden();
        $this->postJson('/targets/'.$target.'/validate', ['status' => 'validated', 'reason' => 'x'])->assertForbidden();
    }

    public function test_bootstrap_admin_uses_temporary_generated_password_and_does_not_overwrite(): void
    {
        $this->artisan('alfar:admin', ['--generate' => true])->assertExitCode(0);
        $admin = User::where('email', 'superadmin@alfar.id')->firstOrFail();
        $this->assertTrue($admin->is_superadmin);
        $this->assertTrue($admin->must_change_password);
        $original = $admin->password;
        $this->artisan('alfar:admin', ['--generate' => true])->assertExitCode(1);
        $this->assertSame($original, $admin->fresh()->password);
    }

    public function test_supervisor_cannot_review_self_by_sending_a_string_employee_id(): void
    {
        $this->grant($this->supervisor, 'reviews.publish', 'self');
        $this->actingAs($this->supervisor)->postJson('/reviews', ['employee_id' => (string) $this->supervisorEmployee, 'period_id' => $this->period, 'analysis' => 'x', 'follow_up' => 'y', 'due_on' => '2026-12-01', 'publish' => true])->assertForbidden();
    }

    public function test_qr_rate_limit_is_separate_from_scanning_and_other_employees(): void
    {
        $this->grant($this->supervisor, 'attendance.scan', 'all');
        $this->actingAs($this->supervisor);
        for ($i = 0; $i < 13; $i++) {
            $this->postJson('/attendance/scan', [])->assertUnprocessable();
        }
        $this->postJson('/attendance/qr')->assertOk();
        $this->actingAs($this->worker);
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/attendance/qr')->assertOk();
        }
        $this->postJson('/attendance/qr')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->actingAs($this->supervisor)->postJson('/attendance/qr')->assertOk();
    }

    public function test_qr_success_notifies_owner_once_and_failed_scan_is_not_saved(): void
    {
        $token = $this->personalToken();
        $device = $this->scannerDevice();
        $payload = ['token' => $token, 'device_id' => $device, 'input_method' => 'laptop_camera', 'event' => 'in'];
        $this->actingAs($this->admin)->postJson('/attendance/scan', $payload)->assertOk();
        $this->assertDatabaseCount('employee_notifications', 1);
        $this->assertDatabaseHas('employee_notifications', ['user_id' => $this->worker->id, 'event' => 'attendance.in', 'title' => 'Absen masuk berhasil', 'read_at' => null]);
        $this->postJson('/attendance/scan', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('employee_notifications', 1);
        $this->actingAs($this->worker)->getJson('/notifications')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.event', 'attendance.in');
        $this->actingAs($this->admin)->getJson('/notifications')->assertJsonPath('total', 0);
    }

    public function test_fingerprint_success_notifies_employee_and_duplicate_does_not_repeat_notification(): void
    {
        $token = str_repeat('c', 64);
        $device = DB::table('devices')->insertGetId(['name' => 'Sidik jari kantor', 'token_hash' => hash('sha256', $token), 'type' => 'fingerprint']);
        DB::table('device_employees')->insert(['device_id' => $device, 'employee_id' => $this->employee, 'external_id' => '42']);
        $event = ['external_event_id' => 'notify-001', 'external_id' => '42', 'event' => 'in', 'occurred_at' => '2026-11-17 08:00:00', 'work_date' => '2026-11-17'];
        $this->withToken($token)->postJson('/api/fingerprint/events', $event)->assertOk();
        $this->withToken($token)->postJson('/api/fingerprint/events', $event)->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseCount('employee_notifications', 1);
        $notification = DB::table('employee_notifications')->first();
        $this->assertSame($this->worker->id, $notification->user_id);
        $this->assertStringContainsString('sidik jari', $notification->body);
        $this->withToken($token)->postJson('/api/fingerprint/events', [...$event, 'external_event_id' => 'notify-002'])->assertUnprocessable();
        $this->assertDatabaseCount('employee_notifications', 1);
    }

    public function test_notifications_can_only_be_read_and_deleted_by_owner_without_deleting_attendance_or_audit(): void
    {
        app(Attendance::class)->record($this->employee, 'in', 'wfo', 'fingerprint', CarbonImmutable::now(), '2026-11-17');
        $id = DB::table('employee_notifications')->value('id');
        $auditCount = DB::table('audit_logs')->count();
        $this->actingAs($this->supervisor)->patchJson('/notifications/'.$id.'/read')->assertNotFound();
        $this->deleteJson('/notifications/'.$id)->assertNotFound();
        $this->getJson('/notifications/feed?after=0')->assertJsonPath('items', [])->assertJsonPath('unread', 0);
        $this->actingAs($this->worker)->getJson('/notifications/feed?after=0')->assertJsonPath('items.0.id', $id)->assertJsonPath('unread', 1);
        $this->patchJson('/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/notifications/feed?after=0')->assertJsonPath('unread', 0);
        $this->deleteJson('/notifications/'.$id)->assertOk();
        $this->assertDatabaseCount('employee_notifications', 0);
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('audit_logs', $auditCount);
    }

    public function test_notification_feed_initializes_cursor_and_delete_all_is_scoped_to_account(): void
    {
        app(Attendance::class)->record($this->employee, 'in', 'wfo', 'fingerprint', CarbonImmutable::now(), '2026-11-17');
        $first = DB::table('employee_notifications')->value('id');
        $this->actingAs($this->worker)->getJson('/notifications/feed')->assertJsonPath('items', [])->assertJsonPath('cursor', $first)->assertJsonPath('unread', 1);
        $this->travel(1)->hours();
        app(Attendance::class)->record($this->employee, 'out', 'wfo', 'fingerprint', CarbonImmutable::now(), '2026-11-17');
        $this->getJson('/notifications/feed?after='.$first)->assertJsonCount(1, 'items')->assertJsonPath('items.0.event', 'attendance.out');
        Access::audit($this->supervisor->id, 'password.change', 'user:'.$this->supervisor->id);
        $this->postJson('/notifications/read-all')->assertOk();
        $this->getJson('/notifications/feed')->assertJsonPath('unread', 0);
        $this->deleteJson('/notifications')->assertOk();
        $this->assertDatabaseMissing('employee_notifications', ['user_id' => $this->worker->id]);
        $this->assertDatabaseHas('employee_notifications', ['user_id' => $this->supervisor->id]);
    }

    public function test_notification_storage_keeps_only_latest_200_and_excludes_password_values(): void
    {
        for ($i = 0; $i < 205; $i++) {
            Access::audit($this->worker->id, 'password.change', 'user:'.$this->worker->id, null, ['password' => 'NEVER_INCLUDE_THIS_SECRET']);
        }
        $this->assertDatabaseCount('employee_notifications', 200);
        $this->actingAs($this->worker)->getJson('/notifications')->assertJsonPath('total', 200)->assertJsonCount(20, 'data')->assertDontSee('NEVER_INCLUDE_THIS_SECRET');
        $this->assertDatabaseCount('audit_logs', 205);
    }

    public function test_schedule_and_published_review_notify_affected_employee(): void
    {
        $this->actingAs($this->admin)->post('/manage/schedules', ['employee_id' => $this->employee, 'shift_id' => $this->shift, 'work_date' => '2026-11-18'])->assertRedirect();
        $this->assertDatabaseHas('employee_notifications', ['user_id' => $this->worker->id, 'event' => 'schedules.save']);
        Access::audit($this->admin->id, 'review.publish', 'employee:'.$this->employee.':period:'.$this->period, null, ['status' => 'published']);
        $this->assertDatabaseHas('employee_notifications', ['user_id' => $this->worker->id, 'event' => 'review.publish']);
    }

    private function bulkScheduleData(?array $ids = null): array
    {
        return ['employee_ids' => $ids ?? [$this->employee, $this->supervisorEmployee], 'shift_id' => $this->shift, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-24', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'existing' => 'skip'];
    }

    public function test_bulk_schedule_creates_a_week_for_multiple_employees_and_notifies_once_each(): void
    {
        $this->actingAs($this->admin)->post('/schedules/bulk', $this->bulkScheduleData())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedules', 15);
        $this->assertDatabaseHas('schedules', ['employee_id' => $this->employee, 'work_date' => '2026-11-24', 'scheduled_in' => '2026-11-24 08:00:00', 'tolerance_minutes' => 10]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('employee_notifications', 3);
        $this->assertDatabaseHas('employee_notifications', ['user_id' => $this->worker->id, 'event' => 'schedules.bulk']);
    }

    public function test_bulk_schedule_respects_selected_weekdays_and_unselected_employees(): void
    {
        $data = [...$this->bulkScheduleData([$this->employee]), 'weekdays' => [1, 2, 3, 4, 5]];
        $this->actingAs($this->admin)->post('/schedules/bulk', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedules', 6);
        $this->assertDatabaseMissing('schedules', ['employee_id' => $this->employee, 'work_date' => '2026-11-21']);
        $this->assertDatabaseMissing('schedules', ['employee_id' => $this->supervisorEmployee, 'work_date' => '2026-11-18']);
    }

    public function test_bulk_schedule_skip_is_repeatable_without_duplicate_rows_or_notifications(): void
    {
        $this->actingAs($this->admin)->post('/schedules/bulk', $this->bulkScheduleData())->assertSessionHasNoErrors();
        $notices = DB::table('employee_notifications')->count();
        $this->post('/schedules/bulk', $this->bulkScheduleData())->assertSessionHasNoErrors();
        $this->post('/schedules/bulk', [...$this->bulkScheduleData(), 'existing' => 'replace'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedules', 15);
        $this->assertDatabaseCount('employee_notifications', $notices);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_bulk_replacement_preserves_attendance_and_handles_overnight_shifts(): void
    {
        app(Attendance::class)->record($this->employee, 'in', 'wfo', 'fingerprint', CarbonImmutable::now(), '2026-11-17');
        DB::table('schedules')->insert(['employee_id' => $this->supervisorEmployee, 'shift_id' => $this->shift, 'work_date' => '2026-11-17', 'scheduled_in' => '2026-11-17 08:00:00', 'scheduled_out' => '2026-11-17 17:00:00', 'tolerance_minutes' => 10, 'created_at' => '2026-10-01 00:00:00']);
        $night = DB::table('shifts')->insertGetId(['name' => 'Malam', 'starts_at' => '22:00', 'ends_at' => '06:00', 'tolerance_minutes' => 15]);
        $data = [...$this->bulkScheduleData(), 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-18', 'shift_id' => $night, 'existing' => 'replace'];
        $this->actingAs($this->admin)->post('/schedules/bulk', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('schedules', ['employee_id' => $this->employee, 'work_date' => '2026-11-17', 'shift_id' => $this->shift, 'scheduled_in' => '2026-11-17 08:00:00']);
        $this->assertDatabaseHas('schedules', ['employee_id' => $this->supervisorEmployee, 'work_date' => '2026-11-17', 'shift_id' => $night, 'scheduled_out' => '2026-11-18 06:00:00', 'tolerance_minutes' => 15, 'created_at' => '2026-10-01 00:00:00']);
        $this->assertDatabaseHas('schedules', ['employee_id' => $this->employee, 'work_date' => '2026-11-18', 'scheduled_out' => '2026-11-19 06:00:00']);
        $this->assertDatabaseCount('attendances', 1);
        $log = DB::table('audit_logs')->where('action', 'schedules.bulk')->first();
        $summary = json_decode($log->after, true);
        $this->assertSame(1, $summary['locked']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(2, $summary['created']);
    }

    public function test_bulk_schedule_rejects_invalid_or_disabled_employee_without_partial_changes(): void
    {
        $this->actingAs($this->admin)->postJson('/schedules/bulk', $this->bulkScheduleData([$this->employee, 999999]))->assertUnprocessable();
        $this->worker->forceFill(['active' => false])->save();
        $this->postJson('/schedules/bulk', $this->bulkScheduleData())->assertUnprocessable();
        $this->assertDatabaseCount('schedules', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_bulk_schedule_requires_global_organization_permission(): void
    {
        $this->postJson('/schedules/bulk', $this->bulkScheduleData())->assertUnauthorized();
        $this->actingAs($this->worker)->postJson('/schedules/bulk', $this->bulkScheduleData())->assertForbidden();
        $this->grant($this->supervisor, 'organization.manage', 'self');
        $this->actingAs($this->supervisor)->postJson('/schedules/bulk', $this->bulkScheduleData())->assertForbidden();
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_bulk_schedule_validates_range_duplicate_employees_and_empty_day_selection(): void
    {
        $this->actingAs($this->admin)->postJson('/schedules/bulk', [...$this->bulkScheduleData(), 'ends_on' => '2026-11-01'])->assertUnprocessable();
        $this->postJson('/schedules/bulk', [...$this->bulkScheduleData(), 'ends_on' => '2028-01-01'])->assertUnprocessable();
        $this->postJson('/schedules/bulk', $this->bulkScheduleData([$this->employee, $this->employee]))->assertUnprocessable();
        $this->postJson('/schedules/bulk', [...$this->bulkScheduleData(), 'weekdays' => []])->assertUnprocessable();
        $this->postJson('/schedules/bulk', [...$this->bulkScheduleData(), 'ends_on' => '2026-11-18', 'weekdays' => [7]])->assertUnprocessable();
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_bulk_schedule_supports_200_employees_over_three_months(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $userId = DB::table('users')->insertGetId(['name' => 'Bulk '.$i, 'email' => 'bulk'.$i.'@alfar.id', 'password' => $this->worker->password, 'active' => true]);
            $ids[] = DB::table('employees')->insertGetId(['user_id' => $userId, 'nik' => 'BULK-'.$i, 'position_id' => $this->position]);
        }
        $data = [...$this->bulkScheduleData($ids), 'starts_on' => '2026-12-01', 'ends_on' => '2027-02-28'];
        $this->actingAs($this->admin)->postJson('/schedules/bulk', [...$data, 'ends_on' => '2027-11-30'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->assertDatabaseCount('schedules', 1);
        $this->actingAs($this->admin)->post('/schedules/bulk', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedules', 18001);
        $this->assertDatabaseCount('employee_notifications', 201);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('schedules', ['employee_id' => $ids[199], 'work_date' => '2027-02-28']);
    }

    public function test_standalone_jobs_and_employee_divisions_control_scope_without_nik_input(): void
    {
        $this->actingAs($this->admin)->post('/manage/positions', ['name' => 'Koordinator'])->assertRedirect()->assertSessionHasNoErrors();
        $position = (int) DB::table('positions')->where('name', 'Koordinator')->value('id');
        $this->assertDatabaseHas('positions', ['id' => $position, 'department_id' => null]);
        $other = DB::table('departments')->insertGetId(['name' => 'Gudang']);
        $data = ['id' => $this->employee, 'user_id' => $this->worker->id, 'position_id' => $position, 'department_id' => $other, 'supervisor_id' => $this->supervisorEmployee, 'starts_on' => '2026-11-17', 'allow_wfo' => true, 'allow_wfh' => false];
        $this->post('/manage/employees', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('employees', ['id' => $this->employee, 'department_id' => $other, 'nik' => 'OP1']);
        $this->assertDatabaseHas('placements', ['employee_id' => $this->employee, 'department_id' => $other, 'position_id' => $position]);
        $this->grant($this->supervisor, 'attendance.read', 'department');
        $this->assertFalse(app(Access::class)->allows($this->supervisor, 'attendance.read', $this->employee));
        $this->post('/manage/employees', [...$data, 'allow_wfh' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('placements', 1);
        $new = User::factory()->create();
        $this->post('/manage/employees', [...$data, 'id' => null, 'user_id' => $new->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringStartsWith('EMP-', DB::table('employees')->where('user_id', $new->id)->value('nik'));
    }

    public function test_deleting_accounts_and_employees_preserves_history_and_blocks_access(): void
    {
        app(Attendance::class)->record($this->employee, 'in', 'wfo', 'web', CarbonImmutable::now(), '2026-11-17');
        $this->actingAs($this->worker)->deleteJson('/manage/employees/'.$this->employee)->assertForbidden();
        $this->actingAs($this->admin)->delete('/manage/employees/'.$this->employee)->assertRedirect();
        $this->assertNotNull(DB::table('employees')->where('id', $this->employee)->value('deleted_at'));
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('schedules', 1);
        $this->assertFalse($this->worker->fresh()->active);
        $this->deleteJson('/manage/accounts/'.$this->admin->id)->assertUnprocessable();
        $this->delete('/manage/accounts/'.$this->worker->id)->assertRedirect();
        $this->assertNotNull($this->worker->fresh()->deleted_at);
        $this->post('/manage/accounts', ['name' => 'Operator baru', 'email' => $this->worker->email, 'password' => 'TemporaryPassword123', 'password_confirmation' => 'TemporaryPassword123'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->worker->fresh()->deleted_at);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_deleting_a_position_keeps_existing_profiles_and_hides_it_from_new_choices(): void
    {
        $this->actingAs($this->admin)->delete('/manage/positions/'.$this->position)->assertRedirect();
        $this->assertDatabaseHas('employees', ['id' => $this->employee, 'position_id' => $this->position]);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->has('positions', 0)->has('employees', 2));
        $this->post('/manage/positions', ['name' => 'Operator'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_calendar_scope_and_schedule_deletion_protect_recorded_attendance(): void
    {
        $this->actingAs($this->supervisor)->getJson('/employees/'.$this->employee.'/calendar?from=2026-11-01&to=2026-11-30')->assertForbidden();
        $this->actingAs($this->worker)->getJson('/employees/'.$this->employee.'/calendar?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(1, 'schedules');
        $this->actingAs($this->admin)->post('/schedules/bulk', ['employee_ids' => [$this->employee], 'shift_id' => $this->shift, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'weekdays' => [3], 'existing' => 'skip'])->assertRedirect();
        app(Attendance::class)->record($this->employee, 'in', 'wfo', 'web', CarbonImmutable::now(), '2026-11-17');
        $this->delete('/employees/'.$this->employee.'/schedules', ['from' => '2026-11-01', 'to' => '2026-11-30'])->assertRedirect();
        $this->assertDatabaseCount('schedules', 1);
        $this->assertDatabaseHas('schedules', ['work_date' => '2026-11-17']);
        $this->getJson('/employees/'.$this->employee.'/calendar?from=2026-01-01&to=2026-11-30')->assertUnprocessable();
    }

    public function test_attention_only_flags_overdue_attendance_and_respects_scope(): void
    {
        $this->grant($this->supervisor, 'attendance.read', 'reports');
        $this->actingAs($this->supervisor)->getJson('/action-center')->assertOk()->assertJsonPath('total', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:11:00'));
        $this->getJson('/action-center')->assertOk()->assertJsonPath('counts.missing_in', 1)->assertJsonPath('data.0.employee_id', $this->employee);
        app(Attendance::class)->record($this->employee, 'in', 'wfo', 'web', CarbonImmutable::now(), '2026-11-17');
        $this->getJson('/action-center')->assertOk()->assertJsonPath('total', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 18:00:00'));
        $this->getJson('/action-center')->assertOk()->assertJsonPath('counts.missing_out', 1);
        $this->getJson('/action-center?from=2026-11-18&to=2026-11-30')->assertOk()->assertJsonPath('total', 0);
        DB::table('attendances')->where('employee_id', $this->employee)->update(['status' => 'sick', 'clock_in' => null]);
        $this->getJson('/action-center')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($this->worker)->getJson('/action-center')->assertForbidden();
    }

    public function test_approval_center_does_not_leak_requests_or_allow_self_approval(): void
    {
        $this->actingAs($this->worker)->post('/attendance/requests', ['type' => 'wfh', 'work_date' => '2026-11-17', 'reason' => 'Kerja dari rumah'])->assertRedirect();
        $this->grant($this->supervisor, 'attendance.read', 'reports');
        $this->actingAs($this->supervisor)->getJson('/action-center?view=approvals')->assertOk()->assertJsonPath('total', 0);
        $this->grant($this->supervisor, 'attendance.approve', 'reports');
        $data = $this->getJson('/action-center?view=approvals')->assertOk()->assertJsonPath('counts.request', 1)->assertJsonPath('data.0.can_decide', true)->json('data.0');
        $this->post('/attendance/requests/'.$data['entity_id'].'/decide', ['status' => 'approved', 'decision_note' => 'Disetujui'])->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson('/action-center?view=approvals')->assertOk()->assertJsonPath('total', 0);
        DB::table('requests')->where('id', $data['entity_id'])->update(['status' => 'pending']);
        $this->grant($this->worker, 'attendance.approve', 'self');
        $this->actingAs($this->worker)->getJson('/action-center?view=approvals')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/attendance/requests/'.$data['entity_id'].'/decide', ['status' => 'approved', 'decision_note' => 'Sendiri'])->assertForbidden();
    }

    public function test_action_center_kpi_queue_tracks_missing_pending_and_stale_decisions(): void
    {
        DB::table('periods')->where('id', $this->period)->update(['status' => 'active']);
        $target = $this->target();
        $this->grant($this->supervisor, 'kpi.validate', 'reports');
        $this->actingAs($this->supervisor)->getJson('/action-center?period='.$this->period)->assertOk()->assertJsonPath('counts.kpi_missing', 1);
        $this->getJson('/action-center?view=approvals&period='.$this->period)->assertOk()->assertJsonPath('total', 0);
        DB::table('submissions')->insert(['target_id' => $target, 'actual' => 98, 'note' => 'QC perlu ditinjau', 'evidence_path' => 'evidence/test.pdf', 'submitted_by' => $this->worker->id, 'status' => 'pending']);
        $this->getJson('/action-center?view=approvals&period='.$this->period)->assertOk()->assertJsonPath('counts.kpi_pending', 1)->assertJsonPath('data.0.actual', 98);
        $this->post('/targets/'.$target.'/validate', ['status' => 'validated', 'reason' => 'Bukti sesuai', 'expected_status' => 'pending'])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/targets/'.$target.'/validate', ['status' => 'rejected', 'reason' => 'Keputusan dari tab lama', 'expected_status' => 'pending'])->assertUnprocessable();
        $this->assertDatabaseHas('submissions', ['target_id' => $target, 'status' => 'validated']);
        $this->getJson('/action-center?view=approvals&period='.$this->period)->assertOk()->assertJsonPath('total', 0);
        DB::table('submissions')->where('target_id', $target)->update(['status' => 'pending', 'submitted_by' => $this->supervisor->id]);
        $this->getJson('/action-center?view=approvals&period='.$this->period)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_review_only_supervisor_opens_incomplete_kpi_in_review_workspace(): void
    {
        DB::table('periods')->where('id', $this->period)->update(['status' => 'active']);
        $this->target();
        $this->grant($this->supervisor, 'reviews.publish', 'reports');
        $this->actingAs($this->supervisor)->getJson('/action-center?period='.$this->period)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.destination', 'reviews')->assertJsonPath('data.0.can_decide', false);
    }

    public function test_action_center_paginates_all_pending_requests_and_filters_without_account_access_leak(): void
    {
        for ($i = 0; $i < 30; $i++) {
            DB::table('requests')->insert(['employee_id' => $this->employee, 'work_date' => CarbonImmutable::parse('2026-11-01')->addDays($i)->toDateString(), 'type' => 'permission', 'reason' => 'Antrean '.$i, 'payload' => '{}', 'status' => 'pending']);
        }
        $this->actingAs($this->admin)->getJson('/action-center?view=approvals')->assertOk()->assertJsonPath('total', 30)->assertJsonPath('last_page', 2)->assertJsonCount(25, 'data');
        $this->getJson('/action-center?view=approvals&page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/action-center?view=approvals&search=Antrean%2029')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/action-center?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
        $this->grant($this->supervisor, 'accounts.manage', 'all');
        $this->actingAs($this->supervisor)->getJson('/action-center')->assertForbidden();
    }

    private function payrollData(): array
    {
        return ['name' => 'Gaji uji November', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-17', 'pays_on' => '2026-11-20', 'employee_ids' => [$this->employee], 'safe_minutes' => 30, 'warning_minutes' => 60, 'free_late_minutes' => 10, 'late_rate' => 500, 'late_cap' => null, 'overtime_rate' => 20000];
    }

    public function test_very_late_checkin_and_late_checkout_are_recorded_without_cutoff(): void
    {
        $attendance = app(Attendance::class);
        $in = $attendance->automaticRecord($this->employee, 'laptop_camera', CarbonImmutable::parse('2026-11-17 23:00:00'));
        $this->assertSame('in', $in['event']);
        $this->assertSame(890, $in['record']->late_minutes);
        $out = $attendance->automaticRecord($this->employee, 'fingerprint', CarbonImmutable::parse('2026-11-18 06:00:00'));
        $this->assertSame('out', $out['event']);
        $this->assertSame('2026-11-17', $out['record']->work_date);
    }

    public function test_payroll_calculates_lateness_overtime_components_and_publishes_immutable_private_slip(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 19:00:00'));
        app(Attendance::class)->record($this->employee, 'in', 'wfo', 'web', CarbonImmutable::parse('2026-11-17 09:03:00'), '2026-11-17');
        $this->actingAs($this->worker)->post('/overtime', ['work_date' => '2026-11-17', 'starts_at' => '2026-11-17 17:00:00', 'ends_at' => '2026-11-17 18:45:00', 'break_minutes' => 15, 'reason' => 'Menyelesaikan pesanan'])->assertRedirect()->assertSessionHasNoErrors();
        $ot = DB::table('overtime_requests')->first();
        $this->actingAs($this->admin)->post('/overtime/'.$ot->id.'/decide', ['status' => 'approved', 'decision_note' => 'Jam kerja sudah diperiksa'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/payroll/profiles/'.$this->employee, ['base_salary' => 3000000, 'components' => [['name' => 'Tunjangan', 'type' => 'earning', 'amount' => 200000], ['name' => 'Iuran', 'type' => 'deduction', 'amount' => 50000]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/payroll/runs', $this->payrollData())->assertRedirect()->assertSessionHasNoErrors();
        $run = DB::table('payroll_runs')->first();
        $item = DB::table('payroll_items')->first();
        $this->post('/payroll/items/'.$item->id, ['components' => [['name' => 'Bonus', 'type' => 'earning', 'amount' => 100000]], 'reason' => 'Bonus periode ini'])->assertRedirect()->assertSessionHasNoErrors();
        $draft = $this->getJson('/payroll/runs/'.$run->id)->assertOk()->json('items.0.snapshot');
        $this->assertSame(53, $draft['late_minutes']);
        $this->assertSame('warning', $draft['zone']);
        $this->assertSame(21500, $draft['late_deduction']);
        $this->assertSame(30000, $draft['overtime_pay']);
        $this->assertSame(3258500, $draft['net']);
        $this->actingAs($this->worker)->getJson('/payroll/slips')->assertOk()->assertExactJson([]);
        $this->get('/payroll/slips/'.$item->id.'/print')->assertNotFound();
        $this->actingAs($this->admin)->post('/payroll/runs/'.$run->id.'/publish')->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->worker)->getJson('/payroll/slips')->assertOk()->assertJsonPath('0.snapshot.net', 3258500);
        $this->get('/payroll/slips/'.$item->id.'/print')->assertOk()->assertSee('3.258.500');
        $this->actingAs($this->supervisor)->getJson('/payroll/slips')->assertExactJson([]);
        $this->get('/payroll/slips/'.$item->id.'/print')->assertNotFound();
        $this->actingAs($this->admin)->postJson('/payroll/runs/'.$run->id.'/recalculate')->assertUnprocessable();
        $this->deleteJson('/payroll/runs/'.$run->id)->assertUnprocessable();
        $this->postJson('/payroll/items/'.$item->id, ['components' => [], 'reason' => 'Coba ubah terbit'])->assertUnprocessable();
        DB::table('salary_profiles')->where('employee_id', $this->employee)->update(['base_salary' => 9000000]);
        DB::table('attendances')->where('employee_id', $this->employee)->update(['late_minutes' => 0]);
        $this->actingAs($this->worker)->getJson('/payroll/slips')->assertJsonPath('0.snapshot.net', 3258500);
        $this->assertDatabaseHas('employee_notifications', ['user_id' => $this->worker->id, 'event' => 'payroll.publish']);
    }

    public function test_payroll_requires_explicit_rules_profiles_and_review_of_pending_overtime(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 19:00:00'));
        $this->actingAs($this->admin)->post('/payroll/runs', [...$this->payrollData(), 'safe_minutes' => null, 'warning_minutes' => null, 'late_rate' => null, 'overtime_rate' => null])->assertRedirect()->assertSessionHasNoErrors();
        $run = DB::table('payroll_runs')->first();
        $this->postJson('/payroll/runs/'.$run->id.'/publish')->assertUnprocessable();
        $this->post('/payroll/runs/'.$run->id.'/policy', ['safe_minutes' => 0, 'warning_minutes' => 0, 'free_late_minutes' => 0, 'late_rate' => 0, 'late_cap' => null, 'overtime_rate' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/payroll/runs/'.$run->id.'/publish')->assertUnprocessable();
        $this->post('/payroll/profiles/'.$this->employee, ['base_salary' => 0, 'components' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/payroll/runs/'.$run->id.'/recalculate')->assertRedirect();
        DB::table('overtime_requests')->insert(['employee_id' => $this->employee, 'work_date' => '2026-11-17', 'starts_at' => '2026-11-17 17:00:00', 'ends_at' => '2026-11-17 18:00:00', 'minutes' => 60, 'reason' => 'Periksa dulu', 'status' => 'pending']);
        $this->postJson('/payroll/runs/'.$run->id.'/publish')->assertUnprocessable();
        DB::table('overtime_requests')->update(['status' => 'rejected']);
        $this->post('/payroll/runs/'.$run->id.'/recalculate')->assertRedirect();
        $this->post('/payroll/runs/'.$run->id.'/publish')->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_payroll_rejects_stale_sources_overlapping_periods_and_unfinished_periods(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 19:00:00'));
        $this->actingAs($this->admin)->post('/payroll/profiles/'.$this->employee, ['base_salary' => 1000000, 'components' => []])->assertRedirect();
        $this->post('/payroll/runs', $this->payrollData())->assertRedirect();
        $run = DB::table('payroll_runs')->first();
        $this->postJson('/payroll/runs', $this->payrollData())->assertUnprocessable();
        DB::table('attendances')->insert(['employee_id' => $this->employee, 'work_date' => '2026-11-17', 'mode' => 'wfo', 'source' => 'web', 'status' => 'present', 'late_minutes' => 75]);
        $this->postJson('/payroll/runs/'.$run->id.'/publish')->assertUnprocessable();
        $this->post('/payroll/runs/'.$run->id.'/recalculate')->assertRedirect();
        $this->getJson('/payroll/runs/'.$run->id)->assertJsonPath('items.0.snapshot.zone', 'red')->assertJsonPath('items.0.snapshot.late_deduction', 32500);
        $this->post('/payroll/runs/'.$run->id.'/publish')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/payroll/runs', [...$this->payrollData(), 'employee_ids' => [$this->supervisorEmployee], 'ends_on' => '2026-11-30'])->assertRedirect()->assertSessionHasNoErrors();
        $future = DB::table('payroll_runs')->orderByDesc('id')->first();
        $this->postJson('/payroll/runs/'.$future->id.'/publish')->assertUnprocessable();
        $this->delete('/payroll/runs/'.$future->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('payroll_runs', ['id' => $future->id]);
        $this->assertDatabaseHas('salary_profiles', ['employee_id' => $this->employee, 'base_salary' => 1000000]);
    }

    public function test_payroll_permissions_are_separate_from_accounts_and_scope_salary_reads(): void
    {
        $this->grant($this->supervisor, 'accounts.manage', 'all');
        $this->actingAs($this->supervisor)->getJson('/payroll')->assertForbidden();
        $this->postJson('/payroll/runs', $this->payrollData())->assertForbidden();
        $this->grant($this->supervisor, 'payroll.manage', 'all');
        $this->getJson('/payroll')->assertOk();
        $this->post('/payroll/runs', $this->payrollData())->assertRedirect()->assertSessionHasNoErrors();
        $run = DB::table('payroll_runs')->first();
        $this->postJson('/payroll/runs/'.$run->id.'/publish')->assertForbidden();
        $this->actingAs($this->worker)->getJson('/payroll/runs/'.$run->id)->assertForbidden();
        $this->postJson('/payroll/profiles/'.$this->employee, ['base_salary' => 9000000, 'components' => []])->assertForbidden();
        $this->grant($this->worker, 'payroll.publish', 'all');
        $this->getJson('/payroll')->assertOk();
        $this->postJson('/payroll/profiles/'.$this->employee, ['base_salary' => 9000000, 'components' => []])->assertForbidden();
    }

    public function test_overtime_requires_approval_excludes_regular_hours_and_prevents_overlap_and_self_approval(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 19:00:00'));
        $data = ['work_date' => '2026-11-17', 'starts_at' => '2026-11-17 17:00:00', 'ends_at' => '2026-11-17 18:30:00', 'break_minutes' => 15, 'reason' => 'Pesanan tambahan'];
        $this->actingAs($this->worker)->postJson('/overtime', [...$data, 'starts_at' => '2026-11-17 16:00:00'])->assertUnprocessable();
        $this->post('/overtime', $data)->assertRedirect()->assertSessionHasNoErrors();
        $item = DB::table('overtime_requests')->first();
        $this->assertSame(75, $item->minutes);
        $this->postJson('/overtime', $data)->assertUnprocessable();
        $this->grant($this->worker, 'overtime.approve', 'self');
        $this->postJson('/overtime/'.$item->id.'/decide', ['status' => 'approved', 'decision_note' => 'Sendiri'])->assertForbidden();
        $this->grant($this->supervisor, 'overtime.approve', 'reports');
        $this->actingAs($this->supervisor)->getJson('/action-center?view=approvals')->assertOk()->assertJsonPath('counts.overtime', 1);
        $this->post('/overtime/'.$item->id.'/decide', ['status' => 'approved', 'decision_note' => 'Sudah diperiksa'])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/overtime/'.$item->id.'/decide', ['status' => 'rejected', 'decision_note' => 'Tab lama'])->assertUnprocessable();
        $this->getJson('/action-center?view=approvals')->assertJsonPath('total', 0);
    }

    public function test_lateness_accumulation_zones_and_caps_use_dynamic_policy_without_exposing_salary(): void
    {
        $this->actingAs($this->admin)->post('/payroll/profiles/'.$this->employee, ['base_salary' => 3000000, 'components' => []])->assertRedirect();
        DB::table('attendances')->insert(['employee_id' => $this->employee, 'work_date' => '2026-11-17', 'mode' => 'wfo', 'source' => 'web', 'status' => 'present', 'late_minutes' => 120]);
        $this->post('/payroll/runs', [...$this->payrollData(), 'late_cap' => 10000])->assertRedirect()->assertSessionHasNoErrors();
        $run = DB::table('payroll_runs')->first();
        $this->getJson('/payroll/runs/'.$run->id)->assertJsonPath('items.0.snapshot.late_deduction', 10000);
        $this->actingAs($this->worker)->getJson('/attendance/lateness?from=2026-11-01&to=2026-11-17&mine=1')->assertOk()->assertJsonPath('0.minutes', 120)->assertJsonPath('0.zone', 'red')->assertJsonMissingPath('0.base_salary');
        $this->grant($this->supervisor, 'attendance.read', 'self');
        $this->actingAs($this->supervisor)->getJson('/attendance/lateness?from=2026-11-01&to=2026-11-17')->assertOk()->assertJsonCount(1);
    }

    public function test_browser_scanners_are_independent_without_registration_and_fingerprint_still_requires_its_own_credentials(): void
    {
        $device = DB::table('devices')->insertGetId(['name' => 'Fingerprint pintu A', 'type' => 'fingerprint', 'active' => true, 'token_hash' => hash('sha256', 'fingerprint-test-token')]);
        foreach (['laptop_camera', 'phone_camera', 'usb_scanner'] as $index => $method) {
            $user = $index === 0 ? $this->worker : User::factory()->create();
            $employee = $index === 0 ? $this->employee : DB::table('employees')->insertGetId(['user_id' => $user->id, 'nik' => 'STATION-'.$index, 'position_id' => $this->position, 'department_id' => $this->department, 'allow_wfo' => true]);
            if ($index !== 0) {
                DB::table('schedules')->insert(['employee_id' => $employee, 'shift_id' => $this->shift, 'work_date' => '2026-11-17', 'scheduled_in' => '2026-11-17 08:00:00', 'scheduled_out' => '2026-11-17 17:00:00', 'tolerance_minutes' => 10]);
            }
            $url = $this->actingAs($user)->postJson('/attendance/qr')->assertOk()->json('url');
            parse_str(parse_url($url, PHP_URL_QUERY), $params);
            $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $params['employee_qr'], 'input_method' => $method])->assertOk()->assertJsonPath('event', 'in');
            $this->assertDatabaseHas('attendances', ['employee_id' => $employee, 'source' => $method]);
        }
        DB::table('schedules')->insert(['employee_id' => $this->supervisorEmployee, 'shift_id' => $this->shift, 'work_date' => '2026-11-17', 'scheduled_in' => '2026-11-17 08:00:00', 'scheduled_out' => '2026-11-17 17:00:00', 'tolerance_minutes' => 10]);
        DB::table('device_employees')->insert(['device_id' => $device, 'employee_id' => $this->supervisorEmployee, 'external_id' => '42']);
        $payload = ['external_event_id' => 'event-1', 'external_id' => '42', 'occurred_at' => '2026-11-17 08:00:00'];
        $this->postJson('/api/fingerprint/events', $payload)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer fingerprint-test-token')->postJson('/api/fingerprint/events', $payload)->assertOk()->assertJsonPath('status', 'recorded');
        $this->assertDatabaseCount('attendances', 4);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('employee_notifications', 4);
    }

    public function test_automatic_scanner_ignores_requested_event_and_does_not_turn_repeat_into_checkout(): void
    {
        DB::table('devices')->insert(['name' => 'Scanner aktif', 'type' => 'scanner', 'active' => true, 'token_hash' => hash('sha256', 'test-scanner')]);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 06:00:00'));
        $qr = $this->actingAs($this->worker)->postJson('/attendance/qr')->json('url');
        parse_str(parse_url($qr, PHP_URL_QUERY), $params);
        $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $params['employee_qr'], 'input_method' => 'usb_scanner', 'event' => 'out'])->assertOk()->assertJsonPath('event', 'in');
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00'));
        $qr = $this->actingAs($this->worker)->postJson('/attendance/qr')->json('url');
        parse_str(parse_url($qr, PHP_URL_QUERY), $params);
        $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $params['employee_qr'], 'input_method' => 'laptop_camera'])->assertUnprocessable();
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee, 'clock_out' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
        $qr = $this->actingAs($this->worker)->postJson('/attendance/qr')->json('url');
        parse_str(parse_url($qr, PHP_URL_QUERY), $params);
        $this->actingAs($this->admin)->postJson('/attendance/scan', ['token' => $params['employee_qr'], 'input_method' => 'laptop_camera', 'event' => 'in'])->assertOk()->assertJsonPath('event', 'out');
        $this->assertDatabaseHas('attendances', ['early_minutes' => 300]);
        $this->assertDatabaseCount('employee_notifications', 2);
    }

    public function test_automatic_attendance_obeys_custom_night_shift_windows_and_missing_checkin(): void
    {
        DB::table('schedules')->where('employee_id', $this->employee)->update(['scheduled_in' => '2026-11-17 22:00:00', 'scheduled_out' => '2026-11-18 06:00:00', 'checkin_early_minutes' => 30, 'checkout_after_minutes' => 180]);
        $attendance = app(Attendance::class);
        $result = $attendance->automaticRecord($this->employee, 'fingerprint', CarbonImmutable::parse('2026-11-17 21:30:00'));
        $this->assertSame('in', $result['event']);
        $result = $attendance->automaticRecord($this->employee, 'fingerprint', CarbonImmutable::parse('2026-11-18 01:00:00'));
        $this->assertSame('out', $result['event']);
        $this->assertSame('2026-11-17', $result['record']->work_date);
        DB::table('attendances')->delete();
        $late = $attendance->automaticRecord($this->employee, 'fingerprint', CarbonImmutable::parse('2026-11-18 02:00:00'));
        $this->assertSame('in', $late['event']);
        $this->assertSame(230, $late['record']->late_minutes);
    }

    public function test_attendance_recap_filters_range_search_and_scope_and_marks_only_elapsed_shifts_absent(): void
    {
        $this->worker->update(['name' => 'Operator Packing']);
        $this->actingAs($this->admin)->getJson('/attendance/report?from=2026-11-01&to=2026-11-30&search=Packing')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.status', 'scheduled');
        $this->travelTo(CarbonImmutable::parse('2026-11-17 18:00:00'));
        $this->getJson('/attendance/report?from=2026-11-01&to=2026-11-30&search=Packing')->assertOk()->assertJsonPath('data.0.status', 'unexcused');
        $this->getJson('/attendance/report?from=2026-11-18&to=2026-11-30')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($this->supervisor)->getJson('/attendance/report?from=2026-11-01&to=2026-11-30')->assertForbidden();
        $this->grant($this->supervisor, 'attendance.read', 'self');
        $this->getJson('/attendance/report?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($this->admin)->getJson('/attendance/report?from=2026-01-01&to=2027-12-31')->assertUnprocessable();
    }
}
