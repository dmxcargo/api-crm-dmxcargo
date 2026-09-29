<?php

namespace App\Shared\Presentation;

use Illuminate\Foundation\Http\FormRequest;

final class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->route()?->getName() ?? '';
        if (str_starts_with($action, 'masters.store')) {
            return ['code' => 'required|string|max:40|regex:/^[A-Z0-9_]{2,40}$/', 'label' => 'required|string|max:100'];
        }
        if (str_starts_with($action, 'masters.update')) {
            return ['label' => 'sometimes|string|max:100', 'isActive' => 'sometimes|boolean'];
        }

        return match ($action) {
            'auth.login' => ['login' => 'required|string|max:254', 'password' => 'required|string|max:128', 'deviceName' => 'required|string|max:80'],
            'users.index' => ['q' => 'nullable|string|max:200', 'page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:100'],
            'users.store' => $this->userRules(false),
            'users.update' => $this->userRules(true),
            'prospects.store' => $this->prospectRules(false),
            'prospects.update' => $this->prospectRules(true),
            'prospects.assign' => ['ownerUserId' => 'required|uuid'],
            'prospects.stage' => ['stage' => 'required|in:NEW,FOLLOW_UP,OPPORTUNITY,QUOTATION,NEGOTIATION,CLOSING,WON,LOST,MAINTENANCE', 'version' => 'required|integer|min:1'],
            'prospects.archive' => ['archived' => 'sometimes|boolean'],
            'contacts.store' => ['name' => 'required|string|max:150', 'position' => 'nullable|string|max:100',
                'phone' => 'nullable|string|max:50', 'email' => 'nullable|email:rfc|max:254', 'primary' => 'sometimes|boolean'],
            'activities.log' => ['type' => 'required|in:CALL,WHATSAPP,EMAIL,MEETING,VISIT,NOTE', 'activityAt' => 'nullable|date',
                'answered' => 'nullable|boolean', 'durationMinutes' => 'nullable|integer|min:0',
                'attendance' => 'nullable|in:HADIR,TIDAK_HADIR,TERJADWAL', 'completion' => 'nullable|in:SELESAI,BATAL,TERJADWAL',
                'notes' => 'nullable|string', 'lastProgress' => 'nullable|string', 'nextAction' => 'nullable|string|max:250',
                'nextFollowUpAt' => 'nullable|date', 'taskPriority' => 'sometimes|in:PENTING,SEDANG,RENDAH'],
            'followups.store' => ['scheduledAt' => 'required|date', 'description' => 'required|string|max:500',
                'priority' => 'sometimes|in:PENTING,SEDANG,RENDAH', 'notes' => 'nullable|string', 'assigneeUserId' => 'nullable|uuid'],
            'followups.reschedule' => ['scheduledAt' => 'required|date', 'priority' => 'sometimes|in:PENTING,SEDANG,RENDAH',
                'status' => 'sometimes|in:BELUM_DIMULAI,BERJALAN,SELESAI,BATAL', 'notes' => 'nullable|string', 'version' => 'required|integer|min:1'],
            'deals.store' => ['quotationNumber' => 'nullable|string|max:80', 'potentialValue' => 'nullable|numeric|min:0',
                'quotationValue' => 'nullable|numeric|min:0', 'paymentStatus' => 'nullable|in:BELUM_DITAGIH,INVOICE,TERTAGIH,LUNAS,OVERDUE'],
            'deals.update' => ['version' => 'required|integer|min:1', 'quotationNumber' => 'nullable|string|max:80',
                'potentialValue' => 'nullable|numeric|min:0', 'quotationValue' => 'nullable|numeric|min:0',
                'paymentStatus' => 'nullable|in:BELUM_DITAGIH,INVOICE,TERTAGIH,LUNAS,OVERDUE',
                'status' => 'prohibited', 'customerId' => 'prohibited'],
            'deals.won' => ['closingDate' => 'required|date', 'closingValue' => 'required|numeric|min:0',
                'customerMode' => 'nullable|in:new,existing', 'customerId' => 'nullable|uuid'],
            'deals.lost' => ['lostReasonCode' => 'required|string|max:40'],
            'deals.payment' => ['paymentStatus' => 'required|in:BELUM_DITAGIH,INVOICE,TERTAGIH,LUNAS,OVERDUE'],
            'customers.store' => ['accountName' => 'required|string|max:200', 'phone' => 'nullable|string|max:50',
                'email' => 'nullable|email:rfc|max:254', 'city' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100',
                'industryCode' => 'nullable|string|max:40'],
            'customers.update' => ['version' => 'required|integer|min:1', 'accountName' => 'sometimes|string|max:200',
                'phone' => 'nullable|string|max:50', 'email' => 'nullable|email:rfc|max:254',
                'city' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100',
                'industryCode' => 'nullable|string|max:40', 'isActive' => 'sometimes|boolean'],
            'customers.repeat' => ['prospectId' => 'required|uuid'],
            'dashboard' => ['owner' => 'nullable|string|max:50', 'stage' => 'nullable|string|max:20',
                'sourceCode' => 'nullable|string|max:40', 'customerType' => 'nullable|string|max:10',
                'priority' => 'nullable|string|max:10', 'from' => 'nullable|date', 'to' => 'nullable|date'],
            'targets.store' => ['userId' => 'required|uuid', 'periodType' => 'required|in:MONTH,YEAR',
                'period' => 'required|string|max:7', 'targetValue' => 'required|numeric|min:0'],
            'targets.update' => ['version' => 'required|integer|min:1', 'targetValue' => 'required|numeric|min:0'],
            'exports.store' => ['columns' => 'sometimes|array|max:30', 'columns.*' => 'string|max:50',
                'q' => 'nullable|string|max:200', 'stage' => 'nullable|string|max:20', 'priority' => 'nullable|string|max:10',
                'city' => 'nullable|string|max:100', 'sourceCode' => 'nullable|string|max:40',
                'dateFrom' => 'nullable|date', 'dateTo' => 'nullable|date', 'owner' => 'nullable|string|max:50'],
            'exports.performance' => ['columns' => 'sometimes|array|max:30', 'columns.*' => 'string|max:50',
                'periodType' => 'required|in:MONTH,YEAR', 'period' => 'required|string|max:7', 'owner' => 'nullable|string|max:50'],
            'reports.performance' => ['periodType' => 'required|in:MONTH,YEAR', 'period' => 'required|string|max:7',
                'groupBy' => 'sometimes|in:sales,self', 'owner' => 'nullable|string|max:50'],
            'reports.annual' => ['year' => 'required|regex:/^\d{4}$/', 'owner' => 'nullable|string|max:50'],
            'archives.preview' => ['owner' => 'nullable|string|max:50',
                'dateFrom' => 'nullable|date', 'dateTo' => 'nullable|date'],
            'archives.store' => ['owner' => 'nullable|string|max:50',
                'dateFrom' => 'nullable|date', 'dateTo' => 'nullable|date'],
            'archives.purge' => ['approved' => 'required|boolean'],
            'imports.store' => ['fileName' => 'required|string|max:255', 'content' => 'required|string|max:22000000'],
            'imports.batches' => ['content' => 'required|string|max:22000000'],
            'imports.review' => ['reviews' => 'required|array|max:10000', 'reviews.*.rowNumber' => 'required|integer|min:1',
                'reviews.*.decision' => 'required|string|max:30', 'reviews.*.version' => 'required|integer|min:1',
                'reviews.*.approvedFields' => 'sometimes|array|max:20', 'reviews.*.approvedFields.*' => 'string|max:50'],
            default => ['page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:100', 'action' => 'sometimes|string|max:80'],
        };
    }

    private function prospectRules(bool $update): array
    {
        $required = $update ? 'sometimes' : 'required';

        return ['accountName' => "$required|string|max:200", 'phone' => "$required|string|max:50", 'email' => 'nullable|email:rfc|max:254',
            'picName' => 'nullable|string|max:150', 'picPosition' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100',
            'sourceCode' => "$required|string|max:40", 'industryCode' => 'nullable|string|max:40',
            'priority' => "$required|in:HOT,WARM,COLD",
            'stage' => $update ? 'prohibited' : 'sometimes|in:NEW,FOLLOW_UP,OPPORTUNITY,QUOTATION,NEGOTIATION,CLOSING,WON,LOST,MAINTENANCE',
            'entryDate' => 'nullable|date', 'nextFollowUpAt' => 'nullable|date', 'nextAction' => 'nullable|string|max:250',
            'lastProgress' => 'nullable|string', 'notes' => 'nullable|string',
            'potentialValue' => 'nullable|numeric|min:0',
            'paymentStatus' => 'nullable|in:BELUM_DITAGIH,INVOICE,TERTAGIH,LUNAS,OVERDUE',
            'customerType' => $update ? 'nullable|in:B2B,B2C' : 'required|in:B2B,B2C',
            'legacyId' => 'nullable|string|max:40', 'legacySourceRow' => 'nullable|integer|min:1',
            'version' => $update ? 'required|integer|min:1' : 'prohibited',
            'id' => 'prohibited', 'ownerUserId' => 'prohibited', 'companyId' => 'prohibited'];
    }

    private function userRules(bool $update): array
    {
        $required = $update ? 'sometimes' : 'required';

        return ['name' => "$required|string|max:150", 'username' => "$required|string|regex:/^[a-z0-9_.-]{3,50}$/D", 'email' => "$required|email:rfc|max:254",
            'password' => "$required|string|min:12|max:72", 'role' => "$required|in:ADMIN,BILLING,SALES",
            'isActive' => $update ? 'sometimes|boolean' : 'prohibited', 'version' => $update ? 'required|integer|min:1' : 'prohibited',
            'id' => 'prohibited', 'ownerUserId' => 'prohibited', 'companyId' => 'prohibited', 'permissions' => 'prohibited', 'access' => 'prohibited'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['username', 'email', 'login'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => strtolower(trim($this->input($key)))]);
            }
        }
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'email' => 'Format email tidak valid.',
            'max' => ':attribute melebihi batas :max.', 'min' => ':attribute minimal :min.', 'regex' => 'Username harus 3-50 karakter: huruf kecil, angka, titik, garis bawah atau tanda hubung.',
            'in' => ':attribute tidak termasuk pilihan yang diizinkan.', 'integer' => ':attribute harus berupa bilangan bulat.',
            'code.regex' => 'Kode harus 2-40 karakter: huruf kapital, angka atau garis bawah.',
            'numeric' => ':attribute harus berupa angka.', 'date' => ':attribute harus berupa tanggal yang valid.',
            'uuid' => ':attribute harus berupa ID yang valid.',
            'boolean' => ':attribute harus bernilai true atau false.', 'prohibited' => ':attribute tidak boleh ditentukan melalui permintaan ini.'];
    }

    public function attributes(): array
    {
        return ['name' => 'Nama', 'username' => 'Username', 'email' => 'Email', 'password' => 'Kata sandi', 'role' => 'Role',
            'login' => 'Username/email', 'deviceName' => 'Nama perangkat', 'version' => 'Versi data', 'isActive' => 'Status aktif', 'page' => 'Halaman', 'pageSize' => 'Ukuran halaman',
            'accountName' => 'Nama account', 'phone' => 'Nomor WhatsApp', 'picName' => 'Nama PIC', 'picPosition' => 'Jabatan PIC',
            'city' => 'Kota', 'province' => 'Provinsi', 'sourceCode' => 'Sumber prospek', 'industryCode' => 'Industry',
            'priority' => 'Priority', 'stage' => 'Stage', 'entryDate' => 'Tanggal masuk', 'nextFollowUpAt' => 'Next follow up',
            'nextAction' => 'Next action', 'lastProgress' => 'Progres terakhir', 'notes' => 'Catatan',
            'potentialValue' => 'Potential value', 'paymentStatus' => 'Status pembayaran', 'customerType' => 'Customer Type',
            'legacyId' => 'ID legacy', 'legacySourceRow' => 'Baris sumber legacy', 'ownerUserId' => 'Owner', 'archived' => 'Arsip',
            'position' => 'Jabatan', 'primary' => 'Kontak utama', 'type' => 'Jenis aktivitas', 'activityAt' => 'Waktu aktivitas',
            'answered' => 'Status dijawab', 'durationMinutes' => 'Durasi', 'attendance' => 'Kehadiran', 'completion' => 'Status selesai',
            'scheduledAt' => 'Jadwal', 'description' => 'Deskripsi', 'priority' => 'Prioritas', 'status' => 'Status',
            'assigneeUserId' => 'Pelaksana', 'quotationNumber' => 'Nomor quotation', 'quotationValue' => 'Quotation value',
            'closingDate' => 'Tanggal closing', 'closingValue' => 'Closing value', 'lostReasonCode' => 'Alasan lost',
            'customerMode' => 'Mode customer', 'customerId' => 'Customer', 'prospectId' => 'Prospect',
            'code' => 'Kode', 'label' => 'Label', 'fileName' => 'Nama file', 'content' => 'Isi file',
            'reviews' => 'Daftar review', 'decision' => 'Keputusan', 'userId' => 'User', 'approved' => 'Persetujuan',
            'periodType' => 'Tipe periode', 'period' => 'Periode', 'targetValue' => 'Nilai target',
            'year' => 'Tahun', 'groupBy' => 'Kelompok', 'columns' => 'Kolom', 'type' => 'Tipe',
            'q' => 'Pencarian', 'dateFrom' => 'Tanggal awal', 'dateTo' => 'Tanggal akhir', 'owner' => 'Owner'];
    }
}
