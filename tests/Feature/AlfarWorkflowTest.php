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
}
