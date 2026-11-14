<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Rekening resmi PPIU (FR-PPIU-03). Rekening baru berstatus pending sampai diverifikasi platform. */
class BankAccountController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $rows = BankAccount::where('tenant_id', $tenant->id)->orderByDesc('is_default')->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($a) => Present::bankAccount($a))->values()]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $request->merge(['account_no' => preg_replace('/[\s.-]/', '', (string) $request->input('account_no'))]);
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:50'],
            'account_no' => ['required', 'regex:/^[0-9]{6,20}$/'],
            'account_holder' => ['required', 'string', 'max:150'],
        ], ['account_no.regex' => 'Nomor rekening hanya angka, 6–20 digit.']);
        if (BankAccount::where('tenant_id', $tenant->id)->where('account_no', $data['account_no'])->where('bank_name', $data['bank_name'])->where('status', '!=', 'inactive')->exists()) {
            throw ApiException::conflict('bank_account_exists', 'Rekening ini sudah terdaftar.');
        }
        $acc = BankAccount::create($data + ['tenant_id' => $tenant->id, 'status' => 'pending', 'is_default' => false]);
        Audit::log('bank_account.created', $acc, null, ['bank_name' => $acc->bank_name, 'account_no_last4' => substr($acc->account_no, -4)], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => Present::bankAccount($acc)], 201);
    }

    public function update(Request $request, Tenant $tenant, string $account): JsonResponse
    {
        $acc = BankAccount::where('tenant_id', $tenant->id)->whereKey($account)->firstOrFail();
        $data = $request->validate(['is_default' => ['sometimes', 'boolean'], 'status' => ['sometimes', 'in:inactive']]);

        DB::transaction(function () use ($acc, $data, $tenant) {
            if (($data['status'] ?? null) === 'inactive') {
                $acc->update(['status' => 'inactive', 'is_default' => false]);
            }
            if (($data['is_default'] ?? false) === true) {
                if ($acc->status !== 'verified') {
                    throw ApiException::unprocessable('bank_account_unverified', 'Hanya rekening terverifikasi yang bisa dijadikan rekening utama.');
                }
                BankAccount::where('tenant_id', $tenant->id)->where('id', '!=', $acc->id)->update(['is_default' => false]);
                $acc->update(['is_default' => true]);
            }
        });
        Audit::log('bank_account.updated', $acc, null, $data, tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => Present::bankAccount($acc->fresh())]);
    }
}
